<?php

/**
 * The high-level code for the getUserData API call.
 * See index.php for usage details.
 */

require_once( '../functions.php' );
require_once( '../api-support-functions.php' );
require_once( '../api-core-functions.php' );

// process the GET parameters
if ( !isset($_GET['parts']) ) {
    errorExit( 400, 'GET parameter "parts" not found.' );
}
$requestedParts = explode( ',', $_GET['parts'] );


/**
 * Run this function when all salesforce http requests succeed
 */
$handleRequestSuccess = function( $responses ) {
    // all the request promises return an associative array. When these rpomises resolve, merge the arrays,
    // cast it to an object, convert it to JSON, and echo the output.
    $masterArray = array();
    foreach( $responses as $response ) {
        $masterArray = array_merge( $masterArray, $response );
    }
    http_response_code( 200 );
    echo json_encode( (object)$masterArray, JSON_PRETTY_PRINT );
};

/**
 * Run this function when any salesforce http request fails (and the access token doesn't need to be refreshed)
 */
$handleRequestFailure = function( $e ) {
    if ( method_exists($e, 'getResponse') ) {
        $message = $e->getResponse()->getBody();
    } else {
        $message = $e->getMessage();
    }
    errorExit( 400, $message );
};


/**
 * function to make simultaneous http requests to salesforce, but uses GET parameters
 * to only use the ones that are needed.
 */
$makeRequests = function() {
    global $appUserID, $requestedParts, $apiFunctions;
    $promises = array();
    // call the appropriate API functions based on the requested parts passed through GET parameters
    foreach( $requestedParts as $part ) {
        // call the API function and store the promise
        $promise = $apiFunctions[$part]( $appUserID );
        array_push( $promises, $promise );
    }
    // return an all-promise so the results of the request can be handled
    return \React\Promise\all( $promises );
};

// verify the firebase login and get the user's firebase uid.
$firebaseUid = verifyFirebaseLogin();
$appUserID = '';

// Get the ID of the AppUser entry in salesforce
getSalesforceAppUserID( $firebaseUid )->then(
    function( $retrievedAppUserID ) {
        global $appUserID;
        return $appUserID = $retrievedAppUserID;
    }
// Use the AppUser ID to make all http requests. If any of them fail, check if the failure is due to
// an expired token. If it is, refresh the token and try the requests again.
)->then(
    $makeRequests
)->then(
    $handleRequestSuccess,
    function( $e ) use ($handleRequestFailure, $handleRequestSuccess) {
        // find out if the error was due to an expired token
        if ( method_exists($e, 'getResponse') && !empty($e->getResponse()) && !empty($e->getResponse()->getBody())  ) {
            $response = $e->getResponse();
            $bodyString = (string)$response->getBody();
            $body = getJsonBodyFromResponse( $response );
            // check if the token was expired so we can refresh it
            if (
                $response->getStatusCode() === 401 &&
                isset( $body[0] ) &&
                isset( $body[0]->errorCode ) &&
                $body[0]->errorCode === 'INVALID_SESSION_ID'
            ) {
                // refresh the token.
                refreshSalesforceTokenAsync()->then( function() use ($handleRequestFailure, $handleRequestSuccess) {
                    // make the original request again.
                    makeRequests()->then( $handleRequestSuccess, $handleRequestFailure );
                }, $handleRequestFailure );
            } else {
                throw $e;
            }
        } else {
            throw $e;
        }
    }
)->then(
    function() {},
    $handleRequestFailure
);

$loop->run();