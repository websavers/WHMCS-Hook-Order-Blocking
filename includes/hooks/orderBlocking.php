<?php
/*
 * Prevent orders from clients with an unverified e-mail
 *
 * @author     WHMCS Josh Q <support@whmcs.com>
 * @copyright  Copyright (c) WHMCS Ltd. All Rights Reserved.
 * @link       https://www.whmcs.com/
 * @reference  https://whmcs.community/topic/339228-prevent-orders-from-clients-with-an-unverified-e-mail-address/
 * @contributor websavers
 */

use WHMCS\Database\Capsule;

if (!defined("WHMCS"))
        die("This file cannot be accessed directly");

# Allow clients with unverified e-mails to place orders?
define("BLOCK_UNVERIFIED_EMAILS", true);
# If brand new client, force going to registration page first and verifying email (extra steps)
define("BLOCK_IF_NOT_REGISTERED", false);
# Block based on client group - if admin has blocked the client from placing orders, like if suspected of fraud
define("BLOCK_CLIENT_GROUP", 'Suspicious');

add_hook("ShoppingCartValidateCheckout", 1, function($vars){
    $client = Menu::context("client");
    if (is_null($client)) {
        if (BLOCK_UNVERIFIED_EMAILS && BLOCK_IF_NOT_REGISTERED){
            logActivity("orderBlocking hook has blocked an order from unverified email address {$vars['email']}");
            return array("You must <a href='/register.php'>register an account</a> and verify your e-mail before you can place an order.");
        }
    }
    else{ //Client Exists
        if (BLOCK_UNVERIFIED_EMAILS && $client->isEmailAddressVerified()==false) {
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