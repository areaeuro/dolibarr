<?php
/* Copyright (C) 2018
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

define("NOLOGIN",1);		// This means this output page does not require to be logged.
define("NOCSRFCHECK",1);	// We accept to go on this page from external web site.

$entity=(! empty($_GET['entity']) ? (int) $_GET['entity'] : (! empty($_POST['entity']) ? (int) $_POST['entity'] : 1));
if (is_numeric($entity)) define("DOLENTITY", $entity);

$res=0;
if (! $res && file_exists("../../main.inc.php")) $res=@include("../../main.inc.php");		// to work if your module directory is into a subdir of root htdocs directory
if (! $res) die("Include of main fails");

if (empty($conf->stripe->enabled)) accessforbidden('',0,0,1);
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/includes/stripe/init.php';
require_once DOL_DOCUMENT_ROOT.'/stripe/class/stripe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/ccountry.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

// You can find your endpoint's secret in your webhook settings
if (isset($_GET['connect'])){
	if (isset($_GET['test']))
	{
		$endpoint_secret =  $conf->global->STRIPE_TEST_WEBHOOK_CONNECT_KEY;
		$service = 'StripeTest';
	}
	else
	{
		$endpoint_secret =  $conf->global->STRIPE_LIVE_WEBHOOK_CONNECT_KEY;
		$service = 'StripeLive';
	}
}
else {
	if (isset($_GET['test']))
	{
		$endpoint_secret =  $conf->global->STRIPE_TEST_WEBHOOK_KEY;
		$service = 'StripeTest';
	}
	else
	{
		$endpoint_secret =  $conf->global->STRIPE_LIVE_WEBHOOK_KEY;
		$service = 'StripeLive';
	}
}
$payload = @file_get_contents("php://input");
$sig_header = $_SERVER["HTTP_STRIPE_SIGNATURE"];
$event = null;

$error = 0;

try {
	$event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
}
catch(\UnexpectedValueException $e) {
	// Invalid payload
	http_response_code(400); // PHP 5.4 or greater
	exit();
} catch(\Stripe\Error\SignatureVerification $e) {
	// Invalid signature
	http_response_code(400); // PHP 5.4 or greater
	exit();
}

// Do something with $event

http_response_code(200); // PHP 5.4 or greater
$langs->load("main");
$user = new User($db);
$user->fetch(5);
$user->getrights();

if (! empty($conf->multicompany->enabled) && ! empty($conf->stripeconnect->enabled)) {
	$sql = "SELECT entity";
	$sql.= " FROM ".MAIN_DB_PREFIX."oauth_token";
	$sql.= " WHERE service = '".$db->escape($service)."' and tokenstring = '%".$db->escape($event->account)."%'";

	dol_syslog(get_class($db) . "::fetch", LOG_DEBUG);
	$result = $db->query($sql);
	if ($result)
	{
		if ($db->num_rows($result))
		{
			$obj = $db->fetch_object($result);
			$key=$obj->entity;
		}
		else {$key=1;
		}
	}
	else {$key=1;
	}
	$ret=$mc->switchEntity($key);
	if (! $res && file_exists("../../main.inc.php")) $res=@include("../../main.inc.php");
	if (! $res) die("Include of main fails");
}

// list of  action
$stripe=new Stripe($db);
if ($event->type == 'payout.created') {
	$error=0;

	$result=dolibarr_set_const($db, $service."_NEXTPAYOUT", date('Y-m-d H:i:s',$event->data->object->arrival_date), 'chaine', 0, '', $conf->entity);

	if ($result > 0)
	{
		// TODO Use CMail and translation
		$body = "Un virement de ".price2num($event->data->object->amount/100)." ".$event->data->object->currency." est attendu sur votre compte le ".date('d-m-Y H:i:s',$event->data->object->arrival_date);
		$subject = '[NOTIFICATION] Virement programmée';
		$headers = 'From: "'.$conf->global->MAIN_INFO_SOCIETE_MAIL.'" <'.$conf->global->MAIN_INFO_SOCIETE_MAIL.'>'; // TODO  convert in dolibarr standard
		mail(''.$conf->global->MAIN_INFO_SOCIETE_MAIL.'', $subject, $body, $headers);
		return 1;
	}
	else
	{
		$error++;
		return -1;
	}
}
elseif ($event->type == 'payout.paid') {
	global $conf;
	$error=0;
	$result=dolibarr_set_const($db, $service."_NEXTPAYOUT",null,'chaine',0,'',$conf->entity);
	if ($result)
	{
		$langs->load("errors");

		$dateo = dol_now();
		$label = $event->data->object->description;
		$amount= $event->data->object->amount/100;
		$amount_to= $event->data->object->amount/100;
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

		$accountfrom=new Account($db);
		$accountfrom->fetch($conf->global->STRIPE_BANK_ACCOUNT_FOR_PAYMENTS);

		$accountto=new Account($db);
		$accountto->fetch($conf->global->STRIPE_BANK_ACCOUNT_FOR_BANKTRANFERS);

		if ($accountto->currency_code != $accountfrom->currency_code) {
			$error++;
			setEventMessages($langs->trans("ErrorTransferBetweenDifferentCurrencyNotPossible"), null, 'errors');
		}

		if ($accountto->id != $accountfrom->id)
		{

			$bank_line_id_from=0;
			$bank_line_id_to=0;
			$result=0;

			// By default, electronic transfert from bank to bank
			$typefrom='PRE';
			$typeto='VIR';

			if (! $error) $bank_line_id_from = $accountfrom->addline($dateo, $typefrom, $label, -1*price2num($amount), '', '', $user);
			if (! ($bank_line_id_from > 0)) $error++;
			if ((! $error) && ($accountto->currency_code == $accountfrom->currency_code)) $bank_line_id_to = $accountto->addline($dateo, $typeto, $label, price2num($amount), '', '', $user);
			if ((! $error) && ($accountto->currency_code != $accountfrom->currency_code)) $bank_line_id_to = $accountto->addline($dateo, $typeto, $label, price2num($amount_to), '', '', $user);
			if (! ($bank_line_id_to > 0)) $error++;

			if (! $error) $result=$accountfrom->add_url_line($bank_line_id_from, $bank_line_id_to, DOL_URL_ROOT.'/compta/bank/ligne.php?rowid=', '(banktransfert)', 'banktransfert');
			if (! ($result > 0)) $error++;
			if (! $error) $result=$accountto->add_url_line($bank_line_id_to, $bank_line_id_from, DOL_URL_ROOT.'/compta/bank/ligne.php?rowid=', '(banktransfert)', 'banktransfert');
			if (! ($result > 0)) $error++;
		}

		// TODO Use CMail and translation
		$body = "Un virement de ".price2num($event->data->object->amount/100)." ".$event->data->object->currency." a ete effectue sur votre compte le ".date('d-m-Y H:i:s',$event->data->object->arrival_date);
		$subject = '[NOTIFICATION] Virement effectué';
		$headers = 'From: "'.$conf->global->MAIN_INFO_SOCIETE_MAIL.'" <'.$conf->global->MAIN_INFO_SOCIETE_MAIL.'>';
		mail(''.$conf->global->MAIN_INFO_SOCIETE_MAIL.'', $subject, $body, $headers);

		return 1;
	}
	else
	{
		$error++;
		return -1;
	}
}
elseif ($event->type == 'charge.succeeded') {

	//TODO: create fees

}
elseif ($event->type == 'customer.source.created') {

	//TODO: save customer's source

}
elseif ($event->type == 'customer.source.updated') {

	//TODO: update customer's source

}
elseif ($event->type == 'customer.source.delete') {

	//TODO: delete customer's source

}
elseif ($event->type == 'charge.failed') {

	$subject = 'Your payment has been received: '.$event->data->object->id.'';
	$headers = 'From: "'.$conf->global->MAIN_INFO_SOCIETE_MAIL.'" <'.$conf->global->MAIN_INFO_SOCIETE_MAIL.'>';
	//mail('ptibogxiv@msn.com', $subject, 'test', $headers);

}
elseif (($event->type == 'source.chargeable') && ($event->data->object->type == 'three_d_secure') && ($event->data->object->three_d_secure->authenticated==true)) {

	$stripe=new Stripe($db);
	$charge=$stripe->CreatePaymentStripe($event->data->object->amount/100,$event->data->object->currency,$event->data->object->metadata->source,$event->data->object->metadata->idsource,$event->data->object->id,$event->data->object->metadata->customer,$stripe->getStripeAccount($service));

	if (isset($charge->id) && $charge->statut=='error'){
		$msg=$charge->message;
		$code=$charge->code;
		$error++;
	}
	elseif (isset($charge->id) && $charge->statut=='success' && $event->data->object->metadata->source=='order') {
		$order=new Commande($db);
		$order->fetch($event->data->object->metadata->idsource);
		$invoice = new Facture($db);
		$idinv=$invoice->createFromOrder($order);

		if ($idinv > 0)
		{
			$result=$invoice->validate($user);
			if ($result > 0) {
				$invoice->fetch($idinv);
				$paiement = $invoice->getSommePaiement();
				$creditnotes=$invoice->getSumCreditNotesUsed();
				$deposits=$invoice->getSumDepositsUsed();
				$ref=$invoice->ref;
				$ifverif=$invoice->socid;
				$currency=$invoice->multicurrency_code;
				$total=price2num($invoice->total_ttc - $paiement - $creditnotes - $deposits,'MT');
			}else{
				$msg=$invoice->error;
				$error++;
			}
		}else{
			$msg=$invoice->error;
			$error++;
		}
	}

	if (!$error){
		$datepaye = dol_now();
		$paiementcode ="CB";
		$amounts=array();
		$amounts[$invoice->id] = $total;
		$multicurrency_amounts=array();
		//$multicurrency_amounts[$item] = $total;
		$paiement = new Paiement($db);
		$paiement->datepaye     = $datepaye;
		$paiement->amounts      = $amounts;   // Array with all payments dispatching
		$paiement->multicurrency_amounts = $multicurrency_amounts;   // Array with all payments dispatching
		$paiement->paiementid   = dol_getIdFromCode($db,$paiementcode,'c_paiement');
		$paiement->num_paiement = $charge->message;
		$paiement->note         = '';
	}

	if (! $error){
		$paiement_id=$paiement->create($user, 0);

		if (empty($conf->global->MAIN_DISABLE_PDF_AUTOUPDATE) && count($invoice->lines)){
			$outputlangs = $langs;
			$newlang = '';
			if ($conf->global->MAIN_MULTILANGS && empty($newlang) && GETPOST('lang_id','aZ09')) $newlang = GETPOST('lang_id','aZ09');
			if ($conf->global->MAIN_MULTILANGS && empty($newlang))	$newlang = $invoice->thirdparty->default_lang;
			if (! empty($newlang)) {
				$outputlangs = new Translate("", $conf);
				$outputlangs->setDefaultLang($newlang);
			}
			$model=$invoice->modelpdf;
			$ret = $invoice->fetch($invoice->id); // Reload to get new records

			$invoice->generateDocument($model, $outputlangs, $hidedetails, $hidedesc, $hideref);
		}
		if ($paiement_id < 0){
			$msg=$paiement->errors;
			$error++;
		}else{
			if ($event->data->object->metadata->source=='order') {
				$order->classifyBilled($user);
			}
		}
	}

	if (! $error){
		$label='(CustomerInvoicePayment)';
		if (GETPOST('type') == 2) $label='(CustomerInvoicePaymentBack)';
		$paiement->addPaymentToBank($user,'payment',$label,$conf->global->STRIPE_BANK_ACCOUNT_FOR_PAYMENTS,'','');
		if ($result < 0)
		{
			$msg=$paiement->errors;
			$error++;
		}
		$invoice->set_paid($user);
	}

	$body = "";
	$subject = 'Facture '.$invoice->ref;
	$headers = 'From: "'.$conf->global->MAIN_INFO_SOCIETE_MAIL.'" <'.$conf->global->MAIN_INFO_SOCIETE_MAIL.'>';
	//mail('ptibogxiv@msn.com', $subject, $body, $headers); TODO  convert in dolibarr standard
}
elseif ($event->type == 'customer.deleted') {
	$db->begin();
	$sql  = "DELETE FROM ".MAIN_DB_PREFIX."societe_account WHERE key_account = '".$event->data->object->id."' and site='stripe' ";
	dol_syslog(get_class($this) . "::delete sql=" . $sql, LOG_DEBUG);
	$db->query($sql);
	$db->commit();
}

