<?php
//
// NRDP Utils
//
// Copyright (c) 2008-2016 - Nagios Enterprises, LLC. All rights reserved.
// License: Nagios Open Software License <http://www.nagios.com/legal/licenses>
//


require_once("constants.inc.php");


if (!defined("callbacks")) {
    $callbacks = array();
}


////////////////////////////////////////////////////////////////////////
// REQUEST FUNCTIONS
////////////////////////////////////////////////////////////////////////


$escape_request_vars=true;
$request_vars_decoded=false;


if (!function_exists('map_htmlentities')) {
    function map_htmlentities($arrval) {
        if (is_array($arrval)) {
            return array_map('map_htmlentities', $arrval);
        } else {
            return htmlentities($arrval, ENT_QUOTES);
        }
    }
}


if (!function_exists('map_htmlentitydecode')) {
    function map_htmlentitydecode($arrval) {
        if (is_array($arrval)) {
            return array_map('map_htmlentitydecode', $arrval);
        } else {
            return html_entity_decode($arrval, ENT_QUOTES);
        }
    }
}


// Grabs POST and GET variables
if (!function_exists('grab_request_vars')) {
    function grab_request_vars($preprocess=true, $type="") {
        global $escape_request_vars;
        global $request;

        // Do we need to strip slashes?
        $strip = false;
        if ((function_exists("get_magic_quotes_gpc") && get_magic_quotes_gpc()) || (ini_get('magic_quotes_sybase') && (strtolower(ini_get('magic_quotes_sybase')) != "off"))) {
            $strip = true;
        }

        $request = array();

        if ($type == "" || $type == "get") {
            foreach ($_GET as $var => $val) {
                if ($escape_request_vars == true) {
                    if (is_array($val)) {
                        $request[$var] = array_map('map_htmlentities', $val);
                    } else {
                        $request[$var] = htmlentities($val, ENT_QUOTES);
                    }
                } else {
                    $request[$var] = $val;
                }
            }
        }

        if ($type == "" || $type == "post") {
            foreach ($_POST as $var => $val) {
                if ($escape_request_vars == true) {
                    if (is_array($val)) {
                        $request[$var] = array_map('map_htmlentities', $val);
                    } else {
                        $request[$var] = htmlentities($val, ENT_QUOTES);
                    }
                } else {
                    $request[$var] = $val;
                }
            }
        }

        // Strip slashes - we escape them later in sql queries
        if ($strip == true) {
            foreach ($request as $var => $val) {
                $request[$var] = stripslashes($val);
            }
        }
    }
}


if (!function_exists('grab_request_var')) {
    function grab_request_var($varname, $default="") {
        global $request;
        global $escape_request_vars;
        global $request_vars_decoded;

        $v = $default;
        if (isset($request[$varname])) {
            if ($escape_request_vars == true && $request_vars_decoded == false) {
                if (is_array($request[$varname])) {
                    $v = array_map('map_htmlentitydecode', $request[$varname]);
                } else {
                    $v = html_entity_decode($request[$varname], ENT_QUOTES);
                }
            } else {
                $v = $request[$varname];
            }
        }

        return $v;
    }
}


if (!function_exists('decode_request_vars')) {
    function decode_request_vars() {
        global $request;
        global $request_vars_decoded;

        $newarr = array();
        foreach ($request as $var => $val) {
            $newarr[$var] = grab_request_var($var);
        }

        $request_vars_decoded = true;
        $request = $newarr;
    }
}


////////////////////////////////////////////////////////////////////////
// OUTPUT FUNCTIONS
////////////////////////////////////////////////////////////////////////


// Generate output header
function output_api_header() {
    global $request;

    // We usually output XML, except if debugging
    if (isset($request['debug'])) {
        if ($request['debug'] == 'text') {
            header("Content-type: text/plain");
        } else {
            header("Content-type: text/html");
        }
    } else {
        header("Content-type: text/xml");
        echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
    }
}


////////////////////////////////////////////////////////////////////////
// MISC FUNCTIONS
////////////////////////////////////////////////////////////////////////


if (!function_exists('have_value')) {
    function have_value($var) {
        if (!isset($var))
            return false;
        else if (is_null($var))
            return false;
        else if (empty($var))
            return false;
        else if ($var == "")
            return false;
        return true;
    }
}


// Gets value from array using default
if (!function_exists('grab_array_var')) {
    function grab_array_var($arr, $varname, $default="") {
        global $request;

        $v = $default;
        if (is_array($arr)) {
            if (array_key_exists($varname, $arr)) {
                $v = $arr[$varname];
            }
        }

        return $v;
    }
}


function xmlentities($uncleaned) {
    $search = array("<", ">", "&", "\"", "'", "’");
    $replace = array("&lt;", "&gt;", "&amp;", "&quot;", "&apos;", "&apos;");
    $cleaned = str_replace($search, $replace, $uncleaned);
    return $cleaned;
}


////////////////////////////////////////////////////////////////////////
// ERROR HANDLING FUNCTIONS
////////////////////////////////////////////////////////////////////////


// Just returns an XML error string and exits execution
function handle_api_error($msg) {
    output_api_header();
    echo "<result>\n";
    echo "  <status>-1</status>\n";
    echo "  <message>" . xmlentities($msg) . "</message>\n";
    echo "</result>\n";
    exit();
}


/////////////////////////////////////////////////
// AUTHENTICATION/AUTHORIZATION FUNCTIONS
/////////////////////////////////////////////////


function check_auth() {
    global $cfg;

    // HTTPS is required
    if (!isset($cfg["require_https"]) || $cfg["require_https"] !== false) {
        if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == "") {
            handle_api_error(ERROR_HTTPS_REQUIRED);
        }
    }

    // Require basic authentication
    if (!isset($cfg["require_basic_auth"]) || $cfg["require_basic_auth"]!==false){
        if (!isset($_SERVER['REMOTE_USER']) || $_SERVER['REMOTE_USER'] == "") {
            handle_api_error(ERROR_NOT_AUTHENTICATED);
        }
        if (isset($cfg["valid_basic_auth_users"])) {
            if (!in_array($_SERVER['REMOTE_USER'], $cfg["valid_basic_auth_users"])) {
                handle_api_error(ERROR_BAD_USER);
            }
        }
    }
}


function check_token() {
    global $cfg;

    // User must supply a token
    $user_token = grab_request_var("token");
    if (!have_value($user_token)) {
        handle_api_error(ERROR_NO_TOKEN_SUPPLIED);
    }

    // No valid tokens are configured
    if (!isset($cfg["authorized_tokens"])) {
        handle_api_error(ERROR_NO_TOKENS_DEFINED);
    }

    // Token must be valid
    if (!in_array($user_token, $cfg["authorized_tokens"])) {
        handle_api_error(ERROR_BAD_TOKEN_SUPPLIED);
    }
}
    
    
/////////////////////////////////////////////////
// UTILITIES
/////////////////////////////////////////////////


if (!function_exists('get_product_name')) {
    function get_product_name() {
        global $cfg;
        return $cfg['product_name'];
    }
}


if (!function_exists('get_product_version')) {
    function get_product_version() {
        global $cfg;
        return $cfg['product_version'];
    }
}


////////////////////////////////////////////////////////////////////////
// CALLBACK FUNCTIONS
////////////////////////////////////////////////////////////////////////


if (!function_exists('do_callbacks')) {
    function do_callbacks($cbtype, &$args) {
        global $callbacks;
        $total_callbacks = 0;

        if (array_key_exists($cbtype, $callbacks)) {
            foreach ($callbacks[$cbtype] as $cb) {
                $cb($cbtype, $args);
                $total_callbacks++;
            }
        }

        return $total_callbacks;
    }
}


if (!function_exists('count_nrdp_callbacks')) {
    function count_nrdp_callbacks($cbtype) {
        global $callbacks;
        $total_callbacks = 0;

        if (array_key_exists($cbtype, $callbacks)) {
            $total_callbacks = count($callbacks[$cbtype]);
        }

        return $total_callbacks;
    }
}


if (!function_exists('register_callback')) {
    function register_callback($cbtype, $func, $prepend=null) {
        global $callbacks;
        
        if ($prepend) {
            array_unshift($callbacks[$cbtype], $func);
        } else {
            $callbacks[$cbtype][] = $func;
        }
    }
}
