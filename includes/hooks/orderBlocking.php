<?php
/*
 * With this module you can:
 * 1. Prevent new orders from clients with an unverified e-mail
 * 2. Force registration before order
 * 3. Prevent new orders from clients in a Group (default: Suspicious -- must be created by admin)
 * 4. Prevent sending order confirmation email when order marked Fraud
 * 5. Change client group or status when order marked Fraud
 *
 * @author     Websavers Inc
 * @link       https://www.websavers.ca
 * @reference  https://whmcs.community/topic/339228-prevent-orders-from-clients-with-an-unverified-e-mail-address/
 * @contributor WHMCS
 */

use WHMCS\Database\Capsule;

if (!defined("WHMCS"))
        die("This file cannot be accessed directly");

# Allow clients with unverified e-mails to place orders?
define("BLOCK_UNVERIFIED_EMAILS", false);
# If brand new client, force going to registration page first and verifying email (extra steps)
define("BLOCK_IF_NOT_REGISTERED", false);
# Block based on client group - if admin has blocked the client from placing orders, like if suspected of fraud
define("BLOCK_CLIENT_GROUP", 'Suspicious');
# This controls whether to send the notification that the order has been held for fraud. You must create an email template called 'Order Fraudulent'
define("FRAUD_SEND_EMAIL", true);
# For fraud orders, close client, or set group as suspicious? Note: close will only happen if there's no active products/domains
define("FRAUD_CLOSE_OR_GROUP", 'group'); //options: 'close', 'group', false

add_hook("ShoppingCartValidateCheckout", 1, function($vars){
    $client = Menu::context("client");
    if (is_null($client)) {
        if (BLOCK_UNVERIFIED_EMAILS && BLOCK_IF_NOT_REGISTERED){
            logActivity("orderBlocking hook has blocked an order from unverified email address {$vars['email']}");
            return array("You must <a href='/register.php'>register an account</a> and verify your e-mail before you can place an order.");
        }
    }
    else{ //Client Exists
        if (BLOCK_UNVERIFIED_EMAILS && $client->isEmailAddressVerified() == false) {
            logActivity("orderBlocking hook has blocked an order from unverified email address {$vars['loginemail']}");
            return array("You must verify  your e-mail address before you can checkout.");
        }
        if (BLOCK_CLIENT_GROUP && $vars['clientid'] > 0){
            $clientgroup = Capsule::table('tblclients')
                ->join('tblclientgroups', 'tblclients.groupid', '=', 'tblclientgroups.id')
                ->where('tblclients.id', $vars['clientid'])
                ->value('groupname');

            if (BLOCK_CLIENT_GROUP == $clientgroup){
                logActivity("orderBlocking hook has blocked an order from client with email address {$vars['loginemail']} for being in client group " . BLOCK_CLIENT_GROUP);
                return array("Your account has been blocked from placing new orders. To appeal, please <a href='submitticket.php?step=2&deptid=1'>open a ticket</a>.");
            }
        }
    }
});

/**
 * Fraud Handling Improvements.
 * Block sending of order confirmation email for fraudulent orders
 * Then send fraud detected email and adjust Client
 */
 
add_hook('EmailPreSend', 1, function($vars){
		
	if ($vars['messagename'] === 'Order Confirmation') {
		
		$orderid = $vars['relid'];
		$response = localAPI('GetOrders', array('id' => $orderid));
		
		if ($response['result'] === 'success'){
			if ($response['orders']['order']['0']['status'] === 'Fraud'){ //can only be 1 order thanks to relid above
				//logActivity("Fraud Order: " . $response['orders']['order']['0']['id']); ///DEBUG
				return array('abortsend' => true); //waboom
			}
		}
		
	}
		
});

add_hook('FraudCheckFailed', 1, function($vars){
		
    $client_id = $vars['clientdetails']['id'];

    // Send fraud notification email
    if (FRAUD_SEND_EMAIL){
        $results = localAPI('SendEmail', array(
            'messagename' => 'Order Fraudulent',
            'id' => $client_id,
        ));
    }

    switch(FRAUD_CLOSE_OR_GROUP){

        case 'close':

            $num_products = Capsule::table('tblhosting')
                ->where('userid', $client_id)
                ->whereIn('domainstatus', ['Active', 'Suspended'])
                ->count();

            $num_domains = Capsule::table('tbldomains')
                ->where('userid', $client_id)
                ->whereIn('status', ['Active', 'Pending Transfer'])
                ->count();

            if ($num_products == 0 && $num_domains == 0){
                localAPI('CloseClient', array('clientid' => $client_id));
            }

            break;

        case 'group':

            $group_id = Capsule::table('tblclientgroups')->where('groupname', BLOCK_CLIENT_GROUP)->value('id');
            
            localAPI('UpdateClient', array('groupid' => $group_id));

            break;

        case false:
        default: 

            break;
            
    }
			

});