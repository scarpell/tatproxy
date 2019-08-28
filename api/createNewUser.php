<?php

/**
 * The high-level code for the createNewUser API call.
 * See index.php for usage details.
 * 
 * Either creates a new Contact object, or fills in details on an existing Contact object.
 */

require_once( '../functions.php' );
require_once( '../api-support-functions.php' );

// verify the firebase login and get the user's firebase uid.
$firebaseUid = verifyFirebaseLogin();
$postData = getPOSTData();

// map POST data to salesforce fields
$sfData = array(
    'TAT_App_Firebase_UID__c' =>    $firebaseUid,
    'TAT_App_Is_Team_Coordinator__c' =>   $postData->isCoordinator
);

if ( !empty($postData->coordinatorId) ) {
    $sfData['TAT_App_Team_Coordinator__c'] = $postData->coordinatorId;
}

if ( empty($postData->salesforceId) ) {
    // only include these fields if we're creating a new Contact object
    $sfData = array_merge( $sfData, array(
        'FirstName' =>                  $postData->firstName,
        'LastName' =>                   $postData->lastName,
        'npe01__HomeEmail__c' =>        $postData->email,
        'npe01__Preferred_Email__c' =>  'Personal',
        'HomePhone' =>                  $postData->phone,
        'npe01__PreferredPhone__c' =>   'Household'
    ));
}

$code = $postData->registrationCode;
makeSalesforceRequestWithTokenExpirationCheck( function() use ($code, $sfData) {
    // verify the registration code
    $escapedCode = escapeSingleQuotes( $code );
    return getAllSalesforceQueryRecordsAsync( "SELECT Id from Account WHERE TAT_App_Registration_Code__c = '{$escapedCode}'" )->then( function($records) use ($code, $sfData) {

        // get special registration codes, which aren't in salesforce
        $regCodes = getSpecialRegistrationCodes();

        if ( $regCodes['individual-volunteer-distributors'] === $code ) {
            $sfData['TAT_App_Volunteer_Type__c'] = 'volunteerDistributor';
            return $sfData;
        } else if ( $regCodes['tat-ambassadors'] === $code ) {
            $sfData['TAT_App_Volunteer_Type__c'] = 'ambassadorVolunteer';
            return $sfData;
        } else {
            if ( sizeof($records) === 0 ) {
                $message = json_encode(array(
                    'errorCode' => 'INCORRECT_REGISTRATION_CODE',
                    'message' => 'The registration code was incorrect.'
                ));
                throw new Exception( $message );
            }
            $sfData['AccountId'] = $records[0]->Id;
            $sfData['TAT_App_Volunteer_Type__c'] = 'volunteerDistributor';
            return $sfData;
        }
    });
})->then( function($sfData) use ($postData, $firebaseUid) {
    // verify that no Contact in salesforce has the given firebaseUid
    return getSalesforceContactID( $firebaseUid )->then(
        function() {
            // we got a ContactID, which means this firebase user already has a salesforce entry! We shouldn't let the user proceed.
            $message = json_encode((object)array(
                'errorCode' => 'FIREBASE_USER_ALREADY_IN_SALESFORCE',
                'message' => 'The specified Firebase user already has an associated Contact entry in Salesforce, and is not allowed to create a new one.'
            ));
            throw new Exception( $message );
        },
        function( $e ) {
            if ( $e && @$e->getMessage() && json_decode( $e->getMessage() )->errorCode === 'FIREBASE_USER_NOT_IN_SALESFORCE' ) {
                // this is what we were looking for; the user can proceed with making a new account.
                return;
            } else {
                throw $e;
            }
        }
    )->then( function() use ($sfData, $postData) {
        if ( empty($postData->salesforceId) ) {
            // create a new Contact object
            return salesforceAPIPostAsync( 'sobjects/Contact/', $sfData );
        } else {
            // update an existing contact object
            // first, get details on the Contact -- if there is no email or phone, add data to those fields
            return salesforceAPIGetAsync(
                "sobjects/Contact/{$postData->salesforceId}/",
                array( 'fields' => 'npe01__HomeEmail__c, HomePhone, npe01__Preferred_Email__c, npe01__PreferredPhone__c' )
            )->then( function($contact) use ($sfData, $postData) {
                if ( empty($contact->npe01__HomeEmail__c) ) {
                    $sfData['npe01__HomeEmail__c'] = $postData->email;
                }
                if ( empty($contact->npe01__Preferred_Email__c) ) {
                    $sfData['npe01__Preferred_Email__c'] = 'Personal';
                }
                if ( empty($contact->HomePhone) ) {
                    $sfData['HomePhone'] = $postData->phone;
                }
                if ( empty($contact->npe01__PreferredPhone__c) ) {
                    $sfData['npe01__PreferredPhone__c'] = 'Household';
                }
                // update the Contact
                return salesforceAPIPatchAsync( "sobjects/Contact/{$postData->salesforceId}/", $sfData );
            });
        }
    })->then( function($response) use($postData, $sfData) {
        $contactId = ( empty($postData->salesforceId) ? $response->id : $postData->salesforceId );

        // echo the ID of the object created/updated
        echo json_encode( (object)array(
            'contactId' => $contactId
        ));
        
        // if the user is a volunteer distributor, add to the volunteer distributor campaign
        if ( $sfData['TAT_App_Volunteer_Type__c'] === 'volunteerDistributor' ) {
            // create a CampaignMember linking the contact to the campaign at 701o000000020AUAAY
            $distributorCampaignId = '701o000000020AUAAY';
            return salesforceAPIPostAsync( 'sobjects/CampaignMember/', array(
                'CampaignId' => $distributorCampaignId,
                'ContactId' => $contactId
            ));
        } else {
            return true;
        }
    });
})->otherwise(
    $handleRequestFailure
);


$loop->run();
