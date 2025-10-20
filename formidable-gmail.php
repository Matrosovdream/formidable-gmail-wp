<?php
/*
Plugin Name: Formidable forms Extension - Gmail parser
Description: 
Version: 1.0
Plugin URI: 
Author URI: 
Author: Stanislav Matrosov
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Variables
define('FRM_GML_BASE_URL', __DIR__);
define('FRM_GML_BASE_PATH', plugin_dir_url(__FILE__));

// Initialize core
require_once 'classes/FrmGmailInit.php';


add_action('init', 'formidable_gmail_init');
function formidable_gmail_init() {
    
    if( isset( $_GET['gmail'] ) ) {

        FrmGmailUpdateEntriesCron::run_one( 0, 1 );
       
        /*echo "<pre>";
        print_r($res);
        echo "</pre>";*/
        die();

    }

    if( isset( $_GET['cln_gmail'] ) ) {
        FrmGmailEntryHelper::cleanCronValues();
        die();
    }

}

add_filter('frm_gmail_before_update_entries', 'custom_gmail_before_update_entries', 10, 5);
function custom_gmail_before_update_entries( $items, $account_idx, $filter_index, $account, $opts ) {

    // 1) Get the filter row safely
    $filter = [];
    if ( isset($account['filters'], $account['filters'][$filter_index]) && is_array($account['filters'][$filter_index]) ) {
        $filter = $account['filters'][$filter_index];
    }

    // 2) Pull the status string (e.g. "In Process, Approved, Shipped")
    $status_str = isset($filter['status']) ? (string) $filter['status'] : '';

    // 3) Normalize into an ordered array (trim + remove empties + dedupe)
    $status_order = [];
    if ( $status_str !== '' ) {
        $parts = preg_split('/\s*,\s*/', $status_str);
        $parts = array_values(array_filter(array_map('trim', (array) $parts), static function($s){ return $s !== ''; }));
        // Deduplicate while preserving order
        $seen = [];
        foreach ($parts as $s) {
            if (!isset($seen[$s])) {
                $status_order[] = $s;
                $seen[$s] = true;
            }
        }
    }

    // If nothing configured, just sort by entryId and return
    $have_order = !empty($status_order);

    // Build a quick lookup: status => rank (lower = higher priority)
    $rank = [];
    if ($have_order) {
        foreach ($status_order as $i => $label) {
            $rank[$label] = $i; // 0,1,2...
        }
    }

    // Preserve original index for stable sort when ranks tie
    foreach ($items as $i => &$it) {
        $it['_orig_i'] = $i;
    }
    unset($it);

    // 4-7) Sort: by entryId asc, then by configured status order, then by original index (stable)
    usort($items, static function($a, $b) use ($rank, $have_order) {

        // Normalize entryId to int if possible (missing goes to the end)
        $aId = isset($a['entryId']) ? (int)$a['entryId'] : 0;
        $bId = isset($b['entryId']) ? (int)$b['entryId'] : 0;

        // Primary: entryId asc; missing/zero at the end
        if ($aId === 0 && $bId !== 0) return 1;   // a after b
        if ($bId === 0 && $aId !== 0) return -1;  // b after a
        if ($aId !== $bId) return ($aId < $bId) ? -1 : 1;

        // Secondary: status by configured order (unknowns last)
        if ($have_order) {
            $aStatus = isset($a['status']) ? (string)$a['status'] : '';
            $bStatus = isset($b['status']) ? (string)$b['status'] : '';

            $aKnown = array_key_exists($aStatus, $rank);
            $bKnown = array_key_exists($bStatus, $rank);

            if ($aKnown && !$bKnown) return -1;
            if ($bKnown && !$aKnown) return 1;

            if ($aKnown && $bKnown) {
                $ar = $rank[$aStatus];
                $br = $rank[$bStatus];
                if ($ar !== $br) return ($ar < $br) ? -1 : 1;
            }
        }

        // Tertiary: stable by original index
        $ao = $a['_orig_i'] ?? 0;
        $bo = $b['_orig_i'] ?? 0;
        if ($ao === $bo) return 0;
        return ($ao < $bo) ? -1 : 1;
    });

    // Cleanup helper keys
    foreach ($items as &$it) {
        unset($it['_orig_i']);
    }
    unset($it);

    
    $item_id = 43011;
    foreach( $items as $key=>$item ) {
        if( $item['entryId'] != $item_id ) {
            unset( $items[$key] );
        }
    }
    
    
    return $items;
}








