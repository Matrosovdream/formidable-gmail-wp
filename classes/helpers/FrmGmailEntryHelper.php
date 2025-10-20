<?php

class FrmGmailEntryHelper {

    /**
     * Upsert a Formidable entry meta value.
     * - If a row exists for (item_id, field_id) → update it
     * - Else → insert a new row
     *
     * Uses direct $wpdb for explicit existence check as requested.
     * Falls back to FrmEntryMeta::update_entry_meta if available.
     *
     * @param int         $entry_id
     * @param int         $field_id
     * @param string|array $value
     * @return bool true on success, false on failure
     */
    public static function updateEntryMeta(int $entry_id, int $field_id, $value): bool {
        
        // Prefer Formidable API if present (it handles caching/serialization).
        /*if ( class_exists('FrmEntryMeta') ) {
            $updated = \FrmEntryMeta::update_entry_meta($entry_id, $field_id, null, $value);
            return (bool) $updated;
        }*/

        // Direct DB fallback
        global $wpdb;
        $table = $wpdb->prefix . 'frm_item_metas';
        $val   = maybe_serialize($value);

        // Check existence
        $meta_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE item_id = %d AND field_id = %d LIMIT 1",
                $entry_id, $field_id
            )
        );

        if ($meta_id) {
            // Update existing
            $res = $wpdb->update(
                $table,
                ['meta_value' => $val],
                ['id' => (int)$meta_id],
                ['%s'],
                ['%d']
            );
            return $res !== false;
        } else {
            // Create new
            $res = $wpdb->insert(
                $table,
                [
                    'item_id'    => $entry_id,
                    'field_id'   => $field_id,
                    'meta_value' => $val,
                ],
                ['%d','%d','%s']
            );
            return $res !== false && (int)$wpdb->insert_id > 0;
        }
    }

    public static function forceEntryUpdate(int $entryId): void {
        
        $entry = FrmEntry::getOne( $entryId, true );
        if ( ! $entry ) { return; }

        $metas = $entry->metas;

        // Trigger Formidable update hooks
        do_action( 'frm_update_entry', $entryId, $entry->form_id, $metas );
        do_action( 'frm_after_update_entry', $entryId, $entry->form_id, $metas );

    }

    public static function cleanCronValues(): void {
        global $wpdb;
    
        $prefix = $wpdb->esc_like('gmail_last_date_update_') . '%';
        $options = $wpdb->get_col(
            $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix)
        );
    
        if (empty($options)) {
            return;
        }
    
        foreach ($options as $opt) {
            delete_option($opt);
        }
    }    

}

    