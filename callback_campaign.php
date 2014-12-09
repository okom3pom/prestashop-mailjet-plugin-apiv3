<?php
/**
 * 2007-2014 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2014 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
*/

require_once(realpath(dirname(__FILE__).'/../../config/config.inc.php'));
if (_PS_VERSION_ < '1.5' || !defined('_PS_ADMIN_DIR_'))
	require_once(realpath(dirname(__FILE__).'/../../init.php'));


if (isset($_POST['data'])) {
	$data = (object) $_POST['data'];
} else if (isset($_POST['mailjet'])) {
	$mailjet = json_decode($_POST['mailjet']);
	$data = $mailjet->data;
} else {
	$data = new stdClass();
}

require_once(dirname(__FILE__).'/mailjet.php');
require_once(dirname(__FILE__).'/classes/MailJetLog.php');

$mj = new Mailjet();

MailJetLog::init();

// Get request response
if(isset($_POST['response'])) {
	$response = (object) $_POST['response'];
}

// Execute actions on the callback side and return response
if (isset($data->next_step_url) && $data->next_step_url) {
	// Check for last changes (this is the moment when we will change the content of the template)
	if($response->message == 'last change of campaigns parameters' || $response->message == 'send details saved successfully') {
		// Get instance for the connection with Mailjet's API - use your API/Secret keys
		$mj_data = new Mailjet_Api($mj->getAccountSettingsKey('API_KEY'), $mj->getAccountSettingsKey('SECRET_KEY'));

		$html = $mj_data->data('newsletter', $data->campaign_id, 'HTML', 'text/html', null, 'GET', 'LAST')->getResponse();
		
		/* On enregistre la campagne en BDD et on génère un token */
		$sql = 'SELECT * FROM '._DB_PREFIX_.'mj_campaign WHERE campaign_id = '.$data->campaign_id;
		$res_campaign = Db::getInstance()->GetRow($sql);

		if (empty($res_campaign))
		{
			$token_presta = md5(uniqid('mj', true));
			$sql = 'INSERT INTO '._DB_PREFIX_.'mj_campaign (campaign_id, token_presta, date_add)
			VALUES ('.$data->campaign_id.', \''.$token_presta.'\', NOW())';
			Db::getInstance()->Execute($sql);

			$sql = 'SELECT * FROM '._DB_PREFIX_.'mj_campaign WHERE campaign_id = '.$data->campaign_id;
			$res_campaign = Db::getInstance()->GetRow($sql);
		}

		$regexp = '<a\s[^>]*href=(\"??)([^\" >]*?)\\1[^>]*>(.*)<\/a>';
		preg_match_all("/$regexp/siU", $html, $liens);

		$debug = '';
		$changed_html = false;

		foreach ($liens[2] as $key => $l)
		{
			$url = str_replace('.', '\.', str_replace('-', '\-', Configuration::get('PS_SHOP_DOMAIN')));

			/* On cherche si on a un lien vers le site et sans le token */
			if (preg_match('`'.$url.'`iUs', $l))
			{
				$changed_html = true;

				$link_without_token = $liens[2][$key];
				if (preg_match('`tokp`iUs', $l))
					$link_without_token = preg_replace('`(\?tokp=.+$)`iUs', '', $link_without_token);

				$debug .= $liens[2][$key]."\r\n";
				$debug .= $link_without_token."\r\n";

				if (!preg_match('`\?`iUs', $liens[2][$key]))
					$link_with_token = $link_without_token.'?tokp='.$res_campaign['token_presta'];
				else
					$link_with_token = $link_without_token.'&tokp='.$res_campaign['token_presta'];

				$debug .= $link_with_token."\r\n";

				$lien_total = preg_replace('`href="'.$liens[2][$key].'`iUs', 'href="'.$link_with_token, $liens[0][$key]);

				$debug .= $lien_total."\r\n";

				$html = str_replace($liens[0][$key], $lien_total, $html);
			}
		}
				
    	$res = $mj_data->data('newsletter', $data->campaign_id, 'HTML', 'text/html', $html, 'PUT', 'LAST')->getResponse();
	}
	
	// Construct the response
	$response = array(
		"code"				=> 1,
		"continue"			=> true,
		"continue_address"	=> $data->next_step_url,
	);
} else {
	// Construct the response
	$response = array(
		"code"		=> 0,
		"continue"	=> false,
		"exit_url"	=> 'SOME URL ADDRESS',
	);
}
// Prepare the response in string format
$json = json_encode($response);

echo $json;

?>