<?php
/** * 1. CONFIGURATION 
 * Create an INBOUND webhook in Developer Resources -> Other -> Inbound Webhook
 * with "CRM" permissions. Copy the URL here.
 */
$rest_url = "https://your-domain.bitrix24.com/rest/1/your-token/";

// Define the path to your custom log file
$log_file = __DIR__ . '/webhook_log.txt';

// Helper function to write to the custom text file
function writeLog($message, $file) {
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] " . $message . PHP_EOL;
    // FILE_APPEND ensures we don't overwrite previous logs
    file_put_contents($file, $log_entry, FILE_APPEND);
}

// =========================================================================
// 2. CAPTURE & LOG DATA FROM OUTBOUND WEBHOOK
// =========================================================================

// LOG ALL FIELDS: Dump the entire POST payload to the text file
writeLog("=== INCOMING BITRIX WEBHOOK POST DATA ===", $log_file);
writeLog(print_r($_POST, true), $log_file);
writeLog("===========================================", $log_file);

// Bitrix sends the Lead ID via POST when a new lead is created
$lead_id = $_POST['data']['FIELDS']['ID'] ?? null;

// LOG SPECIFIC FIELD
writeLog("Extracted Lead ID: " . ($lead_id ? $lead_id : "NONE FOUND"), $log_file);

if (!$lead_id) {
    writeLog("ERROR: No Lead ID received from Bitrix. Exiting script.", $log_file);
    exit;
}
// =========================================================================

/**
 * Helper function for Bitrix REST API calls
 */
function callBitrix($method, $params, $url) {
    $queryUrl = $url . $method . ".json";
    $queryData = http_build_query($params);
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-type: application/x-www-form-urlencoded',
            'content' => $queryData,
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($queryUrl, false, $context);
    return json_decode($result, true);
}

// STEP 1: Fetch the newly created Lead's details
$lead_data = callBitrix('crm.lead.get', ['id' => $lead_id], $rest_url);
$fields = $lead_data['result'];

// Helper to format Multi-fields (Phone/Email) for the new Contact
$formatMultiField = function($items) {
    $output = [];
    if (is_array($items)) {
        foreach ($items as $item) {
            $output[] = [
                "VALUE" => $item['VALUE'],
                "VALUE_TYPE" => $item['VALUE_TYPE']
            ];
        }
    }
    return $output;
};

// STEP 2: Create the Contact
$contact_params = [
    'fields' => [
        'NAME'         => $fields['NAME'],
        'LAST_NAME'    => $fields['LAST_NAME'],
        'SECOND_NAME'  => $fields['SECOND_NAME'],
        'EMAIL'        => $formatMultiField($fields['EMAIL']),
        'PHONE'        => $formatMultiField($fields['PHONE']),
        'OPENED'       => 'Y',
        'TYPE_ID'      => 'CLIENT',
        'ASSIGNED_BY_ID' => $fields['ASSIGNED_BY_ID'] // Keep the same owner
    ]
];

$contact_result = callBitrix('crm.contact.add', $contact_params, $rest_url);
$new_contact_id = $contact_result['result'];

// STEP 3: Link the Lead to the new Contact
if ($new_contact_id) {
    $update_params = [
        'id' => $lead_id,
        'fields' => [
            'CONTACT_ID' => $new_contact_id
        ]
    ];
    callBitrix('crm.lead.update', $update_params, $rest_url);
}

// Log success to your custom text file
writeLog("SUCCESS: Lead #$lead_id successfully linked to Contact #$new_contact_id", $log_file);
?>