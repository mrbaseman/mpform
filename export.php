<?php
/**
 * WebsiteBaker CMS module: mpForm
 * ===============================
 * This module allows you to create customised online forms, such as a feedback form with file upload and customizable email notifications. mpForm allows forms over one or more pages, loops of forms, conditionally displayed sections within a single page, and many more things.  User input for the same session_id will become a single row in the submitted table.  Since Version 1.1.0 many ajax helpers enable you to speed up the process of creating forms with this module. Since 1.2.0 forms can be imported and exported directly in the module.
 *
 * @category            page
 * @module              mpform
 * @version             1.3.44
 * @authors             Frank Heyne, NorHei(heimsath.org), Christian M. Stefan (Stefek), Martin Hecht (mrbaseman) and others
 * @copyright           (c) 2009-2013 Frank Heyne, Stefek, Norhei, 2014-2022 Martin Hecht (mrbaseman)
 * @url                 https://github.com/mrbaseman/mpform
 * @license             GNU General Public License
 * @platform            2.8.x
 * @requirements        php >= 5.3
 *
 **/
/* This file exports the whole section (excluding the submissions) to an xml file.
   The code was taken form the export_section module and integrated into mpform now.

   2026-07: rewritten to use information_schema for table/column discovery
   instead of SHOW TABLES / SHOW COLUMNS ... LIKE, and to use parameterized
   queries throughout. This is more reliable under the PDO-based Database
   class and avoids relying on driver-specific numeric-index quirks of
   fetchRow(). */

unset($_GET['page_id']);
unset($_GET['section_id']);

// manually include the config.php file (defines the required constants)
require('../../config.php');

// include core functions of WB 2.7 to edit the optional module CSS files (frontend.css, backend.css)
@include_once(WB_PATH .'/framework/module.functions.php');

require_once(dirname(__FILE__).'/constants.php');

// obtain module directory
$mod_dir = basename(dirname(__FILE__));

// include the module language file depending on the backend language of the current user
if (!@include(get_module_language_file($mod_dir))) return;

// include WB admin wrapper script to check permissions
$admin_header = false;
require(WB_PATH . '/modules/admin.php');
if (( method_exists( $admin, 'checkFTAN' )  && (!$admin->checkFTAN()))
    && (!(defined('MPFORM_SKIP_FTAN')&&(MPFORM_SKIP_FTAN)))) {
    $admin->print_header();
    $admin->print_error($MESSAGE['GENERIC_SECURITY_ACCESS']
        .' (FTAN) '.__FILE__.':'.__LINE__,
        ADMIN_URL.'/pages/modify.php?page_id='.(int)$page_id);
    $admin->print_footer();
    exit();
}

// load the section row once; it's needed both for the id check below
// and for the export itself, so there is no need to query it twice
$query_content = $database->query(
    "SELECT * FROM ".TABLE_PREFIX."sections WHERE section_id = ?",
    [$section_id]
);
$section_row = $query_content ? $query_content->fetchRow(MYSQLI_ASSOC) : null;

// protect from cross site scripting
if ((!$section_row || $section_row['page_id'] != $page_id)
    && (!(defined('MPFORM_SKIP_ID_CHECK')&&(MPFORM_SKIP_ID_CHECK)))) {
    $sUrlToGo = ADMIN_URL."/pages/index.php";
    if(headers_sent())
      $admin->print_error($MESSAGE['GENERIC_SECURITY_ACCESS']
      .' (ID_CHECK) '.__FILE__.':'.__LINE__,
      $sUrlToGo);
    else
      header("Location: ". $sUrlToGo);
    exit(0);
}

/**
 * Escapes a value for safe placement inside a CDATA section.
 * CDATA blocks may contain anything except the literal sequence "]]>",
 * so that sequence has to be broken up across two adjacent CDATA blocks.
 */
function mpform_export_cdata(?string $value): string
{
    $value = $value ?? '';
    return str_replace(']]>', ']]]]><![CDATA[>', $value);
}

$lines = array();
$lines[] = '<?xml  version="1.0" encoding="'. DEFAULT_CHARSET .'" ?>';

if (!$section_row) {
    // section no longer exists - nothing to export
    $admin->print_header();
    $admin->print_error("Section $section_id not found",
        ADMIN_URL.'/pages/modify.php?page_id='.(int)$page_id);
    $admin->print_footer();
    exit;
}

if ($section_row['module'] != 'mpform') {
    $admin->print_header();
    $admin->print_error("Export of sections of type ".$section_row['module']." is not possible",
        ADMIN_URL.'/pages/modify.php?page_id='.(int)$page_id);
    $admin->print_footer();
    exit;
}

$lines[] = "<export_section>";
$lines[] = "\t<module>";
$lines[] = "\t\t<name>".$section_row['module']."</name>";

$results = $database->query(
    "SELECT * FROM ".TABLE_PREFIX."addons WHERE directory = ?",
    [$section_row['module']]
);
if ($results && $addon_row = $results->fetchRow(MYSQLI_ASSOC)) {
    $lines[] = "\t\t<version>".$addon_row['version']."</version>";
}
$lines[] = "\t</module>";

// ── Discover all module tables that carry a section_id column ──────────────
// Instead of SHOW TABLES + SHOW COLUMNS ... LIKE (which turned out to behave
// unreliably through the PDO wrapper), ask information_schema directly for
// every table in the current database that starts with "<prefix>mod_" and
// has a `section_id` column. This is a single, portable, MySQL/MariaDB-
// standard query.
$prefix_pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], TABLE_PREFIX.'mod_') . '%';
$tables_result = $database->query(
    "SELECT DISTINCT c.TABLE_NAME"
    . " FROM information_schema.COLUMNS c"
    . " WHERE c.TABLE_SCHEMA = DATABASE()"
    . " AND c.COLUMN_NAME = 'section_id'"
    . " AND c.TABLE_NAME LIKE ? ESCAPE '\\\\'"
    . " ORDER BY c.TABLE_NAME",
    [$prefix_pattern]
);

$export_tables = [];
if ($tables_result) {
    while ($t = $tables_result->fetchRow(MYSQLI_ASSOC)) {
        $tablename = $t['TABLE_NAME'];
        // skip submissions from form / formx / mpform modules
        if (strpos($tablename, TABLE_PREFIX.'mod_form_submissions') === 0) continue;
        if (strpos($tablename, TABLE_PREFIX.'mod_formx_submissions') === 0) continue;
        if (strpos($tablename, TP_MPFORM.'submissions') === 0) continue;
        $export_tables[] = $tablename;
    }
}

foreach ($export_tables as $tablename) {
    // find the auto_increment column of this table (if any), so it can be
    // excluded from the export - a new value is assigned again on import.
    // IMPORTANT: this must only exclude AUTO_INCREMENT columns, not every
    // primary key column - some tables (e.g. mod_mpform_settings) use
    // section_id itself as a non-autoincrement primary key, and that value
    // is required on import (import.php re-injects the current section_id
    // only for fields that are actually present in the export).
    // This mirrors import.php's own detection query exactly:
    //   SHOW COLUMNS FROM `$tn` WHERE extra LIKE 'auto_increment'
    $pk_result = $database->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS"
        . " WHERE TABLE_SCHEMA = DATABASE()"
        . " AND TABLE_NAME = ?"
        . " AND EXTRA LIKE '%auto_increment%'"
        . " LIMIT 1",
        [$tablename]
    );
    $pk_row = $pk_result ? $pk_result->fetchRow(MYSQLI_ASSOC) : null;
    $pk_column = $pk_row['COLUMN_NAME'] ?? null;

    // table name comes from information_schema (trusted), not user input,
    // so it's safe to interpolate into the identifier position here
    $results2 = $database->query(
        "SELECT * FROM `" . $tablename . "` WHERE section_id = ?",
        [$section_id]
    );

    $inside_tab = false;
    while ($results2 && $row2 = $results2->fetchRow(MYSQLI_ASSOC)) {
        if (!$inside_tab) {
            $tn = substr($tablename, strlen(TABLE_PREFIX));
            $lines[] = "\t<export_section_table>";
            $lines[] = "\t\t<tablename>$tn</tablename>";
            $inside_tab = true;
        }
        $lines[] = "\t\t<export_section_row>";
        foreach ($row2 as $k => $v) {
            if ($pk_column !== null && $k === $pk_column) continue;
            $cv = mpform_export_cdata($v);
            $lines[]
                = "\t\t\t<export_section_field>"
                . "<fieldn>$k</fieldn>"
                . "<fieldv><![CDATA[" . $cv . "]]></fieldv>"
                . "</export_section_field>";
        }
        $lines[] = "\t\t</export_section_row>";
    }
    if ($inside_tab) {
        $lines[] = "\t</export_section_table>";
    }
}

$lines[] = "</export_section>";

header("Content-Type: text/plain");
header("Content-Disposition: attachment; filename=section_$section_id.xml");
foreach ($lines as $l) echo "$l\r\n";
