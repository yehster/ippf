<?php
// Copyright (C) 2005-2011 Rod Roark <rod@sunsetsystems.com>
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.

$fake_register_globals=false;
$sanitize_all_escapes=true;

require_once("../../globals.php");
require_once("$srcdir/acl.inc");
require_once("$srcdir/api.inc");
require_once("$srcdir/forms.inc");
require_once("codes.php");
require_once("../../../custom/code_types.inc.php");
require_once("../../drugs/drugs.inc.php");
require_once("$srcdir/formatting.inc.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/formdata.inc.php");
require_once("$srcdir/appointment_status.inc.php");
require_once("$srcdir/classes/Prescription.class.php");
require_once("ippf/configuration.php");
require_once("ippf/contraception.php");
require_once("ippf/appointment_button.php");

// Some table cells will not be displayed unless insurance billing is used.
$usbillstyle = $GLOBALS['ippf_specific'] ? " style='display:none'" : "";

$alertmsg = '';

// Get the user's default warehouse and an indicator if there's a choice of warehouses.
$wrow = sqlQuery("SELECT count(*) AS count FROM list_options WHERE list_id = 'warehouse'");
$got_warehouses = $wrow['count'] > 1;
$wrow = sqlQuery("SELECT default_warehouse FROM users WHERE username = '" .
  $_SESSION['authUser'] . "'");
$default_warehouse = empty($wrow['default_warehouse']) ? '' : $wrow['default_warehouse'];

function alphaCodeType($id) {
  global $code_types;
  foreach ($code_types as $key => $value) {
    if ($value['id'] == $id) return $key;
  }
  return '';
}

// Helper function for creating drop-lists.
function endFSCategory() {
  global $i, $last_category, $FEE_SHEET_COLUMNS;
  if (! $last_category) return;
  echo "   </select>\n";
  echo "  </td>\n";
  if ($i >= $FEE_SHEET_COLUMNS) {
    echo " </tr>\n";
    $i = 0;
  }
}

// Generate JavaScript to build the array of diagnoses.
function genDiagJS($code_type, $code) {
  global $code_types;
  if ($code_types[$code_type]['diag']) {
    echo "diags.push('" . attr($code_type) . "|" . attr($code) . "');\n";
  }
}

// For IPPF only.  Returns 0 = none, 1 = nonsurgical, 2 = surgical.
//
function contraceptionClass($code_type, $code) {
  global $code_types;
  if (!$GLOBALS['ippf_specific']) return 0;
  $contra = 0;
  // Get the related service codes.
  $codesrow = sqlQuery("SELECT related_code FROM codes WHERE " .
    "code_type = ? " .
    " AND code = ? LIMIT 1", array($code_types[$code_type]['id'],$code) );
  if (!empty($codesrow['related_code']) && $code_type == 'MA') {
    $relcodes = explode(';', $codesrow['related_code']);
    foreach ($relcodes as $relstring) {
      if ($relstring === '') continue;
      list($reltype, $relcode) = explode(':', $relstring);
      if ($reltype !== 'IPPF') continue;
      if      (preg_match('/^11....110/'    , $relcode)) $contra |= 1;
      else if (preg_match('/^11....999/'    , $relcode)) $contra |= 1;
      else if (preg_match('/^112152010/'    , $relcode)) $contra |= 1;
      else if (preg_match('/^11317[1-2]111/', $relcode)) $contra |= 1;
      else if (preg_match('/^12118[1-2].13/', $relcode)) $contra |= 2;
      else if (preg_match('/^12118[1-2]999/', $relcode)) $contra |= 2;
    }
  }
  return $contra;
}

// This writes a billing line item to the output page.
//
function echoLine($lino, $codetype, $code, $modifier, $ndc_info='',
  $auth = TRUE, $del = FALSE, $units = NULL, $fee = NULL, $id = NULL,
  $billed = FALSE, $code_text = NULL, $justify = NULL, $provider_id = 0, $notecodes='')
{
  global $code_types, $ndc_applies, $ndc_uom_choices, $justinit, $pid;
  global $contraception, $usbillstyle, $hasCharges;

  if ($codetype == 'COPAY') {
    if (!$code_text) $code_text = 'Cash';
    if ($fee > 0) $fee = 0 - $fee;
  }
  if (! $code_text) {
    $sqlArray = array();
    $query = "select id, units, code_text from codes where code_type = ? " .
      " and " .
      "code = ? and ";
    array_push($sqlArray,$code_types[$codetype]['id'],$code);
    if ($modifier) {
      $query .= "modifier = ?";
      array_push($sqlArray,$modifier);
    } else {
      $query .= "(modifier is null or modifier = '')";
    }
    $result = sqlQuery($query, $sqlArray);
    $code_text = $result['code_text'];
    if (empty($units)) $units = max(1, intval($result['units']));
    if (!isset($fee)) {
      // Fees come from the prices table now.
      $query = "SELECT prices.pr_price " .
        "FROM patient_data, prices WHERE " .
        "patient_data.pid = ? AND " .
        "prices.pr_id = ? AND " .
        "prices.pr_selector = '' AND " .
        "prices.pr_level = patient_data.pricelevel " .
        "LIMIT 1";
      echo "\n<!-- $query -->\n"; // debugging
      $prrow = sqlQuery($query, array($pid,$result['id']) );
      $fee = empty($prrow) ? 0 : $prrow['pr_price'];
    }
  }
  $fee = sprintf('%01.2f', $fee);
  if (empty($units)) $units = 1;
  $units = max(1, intval($units));
  // We put unit price on the screen, not the total line item fee.
  $price = $fee / $units;
  $strike1 = ($id && $del) ? "<strike>" : "";
  $strike2 = ($id && $del) ? "</strike>" : "";
  echo " <tr>\n";
  echo "  <td class='billcell'>$strike1" .
    ($codetype == 'COPAY' ? xl($codetype) : $codetype) . $strike2;
  //if the line to ouput is copay, show the date here passed as $ndc_info,
  //since this variable is not applicable in the case of copay.
  if($codetype == 'COPAY'){
    echo "(".htmlspecialchars($ndc_info).")";
    $ndc_info = '';
  }
  if ($id) {
    echo "<input type='hidden' name='bill[".attr($lino)."][id]' value='$id'>";
  }
  echo "<input type='hidden' name='bill[".attr($lino)."][code_type]' value='".attr($codetype)."'>";
  echo "<input type='hidden' name='bill[".attr($lino)."][code]' value='".attr($code)."'>";
  echo "<input type='hidden' name='bill[".attr($lino)."][billed]' value='".attr($billed)."'>";
  echo "</td>\n";
  if ($codetype != 'COPAY') {
    echo "  <td class='billcell'>$strike1" . text($code) . "$strike2</td>\n";
  } else {
    echo "  <td class='billcell'>&nbsp;</td>\n";
  }
  if ($billed) {
    if (modifiers_are_used(true)) {
      echo "  <td class='billcell'>$strike1" . text($modifier) . "$strike2" .
        "<input type='hidden' name='bill[".attr($lino)."][mod]' value='".attr($modifier)."'></td>\n";
    }
    if (fees_are_used()) {
      echo "  <td class='billcell' align='right'>" . text(oeFormatMoney($price)) . "</td>\n";
      if ($codetype != 'COPAY') {
        echo "  <td class='billcell' align='center'>" . text($units) . "</td>\n";
      } else {
        echo "  <td class='billcell'>&nbsp;</td>\n";
      }
    }
    if (justifiers_are_used()) {
      echo "  <td class='billcell' align='center'$usbillstyle>" . text($justify) . "</td>\n";
    }

    // Show provider for this line.
    echo "  <td class='billcell' align='center'>";
    genProviderSelect('', '-- '.xl("Default").' --', $provider_id, true);
    echo "</td>\n";
    if ($code_types[$codetype]['claim'] && !$code_types[$codetype]['diag']) {
      echo "  <td class='billcell' align='center'$usbillstyle>" .
        htmlspecialchars($notecodes, ENT_NOQUOTES) . "</td>\n";
    }
    else {
      echo "  <td class='billcell' align='center'$usbillstyle></td>\n";
    }
    echo "  <td class='billcell' align='center'$usbillstyle><input type='checkbox'" .
      ($auth ? " checked" : "") . " disabled /></td>\n";
    if ($GLOBALS['gbl_auto_create_rx']) {
      echo "  <td class='billcell' align='center'>&nbsp;</td>\n";
    }    
    echo "  <td class='billcell' align='center'><input type='checkbox'" .
      " disabled /></td>\n";
  }
  else { // not billed
    if (modifiers_are_used(true)) {
      if ($codetype != 'COPAY' && ($code_types[$codetype]['mod'] || $modifier)) {
        echo "  <td class='billcell'><input type='text' name='bill[".attr($lino)."][mod]' " .
             "value='" . attr($modifier) . "' " .
             "title='" . xla("Multiple modifiers can be separated by colons or spaces, maximum of 4 (M1:M2:M3:M4)") . "' " .
             "value='" . attr($modifier) . "' size='" . attr($code_types[$codetype]['mod']) . "'></td>\n";
      } else {
        echo "  <td class='billcell'>&nbsp;</td>\n";
      }
    }
    if (fees_are_used()) {
      if ($codetype == 'COPAY' || $code_types[$codetype]['fee'] || $fee != 0) {
        echo "  <td class='billcell' align='right'>" .
          "<input type='text' name='bill[".attr($lino)."][price]' " .
          "value='" . attr($price) . "' size='6' onchange='setSaveAndClose()";
        if (acl_check('acct','disc'))
          echo " style='text-align:right'";
        else
          echo " style='text-align:right;background-color:transparent' readonly";
        echo "></td>\n";
        echo "  <td class='billcell' align='center'>";
        if ($codetype != 'COPAY') {
          echo "<input type='text' name='bill[".attr($lino)."][units]' " .
          "value='" . attr($units) . "' size='2' style='text-align:right'>";
        } else {
          echo "<input type='hidden' name='bill[".attr($lino)."][units]' value='" . attr($units) . "'>";
        }
        echo "</td>\n";
      } else {
        echo "  <td class='billcell'>&nbsp;</td>\n";
        echo "  <td class='billcell'>&nbsp;</td>\n";
      }
    }
    if (justifiers_are_used()) {
      if ($code_types[$codetype]['just'] || $justify) {
        echo "  <td class='billcell' align='center'$usbillstyle ";
        echo "title='" . xla("Select one or more diagnosis codes to justify the service") . "' >";
        echo "<select name='bill[".attr($lino)."][justify]' onchange='setJustify(this)'>";
        echo "<option value='" . attr($justify) . "'>" . text($justify) . "</option></select>";
        echo "</td>\n";
        $justinit .= "setJustify(f['bill[".attr($lino)."][justify]']);\n";
      } else {
        echo "  <td class='billcell'$usbillstyle>&nbsp;</td>\n";
      }
    }

    // Provider drop-list for this line.
    echo "  <td class='billcell' align='center'>";
    genProviderSelect("bill[$lino][provid]", '-- '.xl("Default").' --', $provider_id);
    echo "</td>\n";
    if ($code_types[$codetype]['claim'] && !$code_types[$codetype]['diag']) {
      echo "  <td class='billcell' align='center'$usbillstyle><input type='text' name='bill[".attr($lino)."][notecodes]' " .
        "value='" . htmlspecialchars($notecodes, ENT_QUOTES) . "' maxlength='10' size='8' /></td>\n";
    }
    else {
      echo "  <td class='billcell' align='center'$usbillstyle></td>\n";
    }
    echo "  <td class='billcell' align='center'$usbillstyle><input type='checkbox' name='bill[".attr($lino)."][auth]' " .
      "value='1'" . ($auth ? " checked" : "") . " /></td>\n";
    if ($GLOBALS['gbl_auto_create_rx']) {
      echo "  <td class='billcell' align='center'>&nbsp;</td>\n";
    }    
    echo "  <td class='billcell' align='center'><input type='checkbox' name='bill[".attr($lino)."][del]' " .
      "value='1'" . ($del ? " checked" : "") . " /></td>\n";
  }

  echo "  <td class='billcell'>$strike1" . text($code_text) . "$strike2</td>\n";
  echo " </tr>\n";

  // If NDC info exists or may be required, add a line for it.
  if ($codetype == 'HCPCS' && $ndc_applies && !$billed) {
    $ndcnum = ''; $ndcuom = ''; $ndcqty = '';
    if (preg_match('/^N4(\S+)\s+(\S\S)(.*)/', $ndc_info, $tmp)) {
      $ndcnum = $tmp[1]; $ndcuom = $tmp[2]; $ndcqty = $tmp[3];
    }
    echo " <tr>\n";
    echo "  <td class='billcell' colspan='2'>&nbsp;</td>\n";
    echo "  <td class='billcell' colspan='6'>&nbsp;NDC:&nbsp;";
    echo "<input type='text' name='bill[".attr($lino)."][ndcnum]' value='" . attr($ndcnum) . "' " .
      "size='11' style='background-color:transparent'>";
    echo " &nbsp;Qty:&nbsp;";
    echo "<input type='text' name='bill[".attr($lino)."][ndcqty]' value='" . attr($ndcqty) . "' " .
      "size='3' style='background-color:transparent;text-align:right'>";
    echo " ";
    echo "<select name='bill[".attr($lino)."][ndcuom]' style='background-color:transparent'>";
    foreach ($ndc_uom_choices as $key => $value) {
      echo "<option value='" . attr($key) . "'";
      if ($key == $ndcuom) echo " selected";
      echo ">" . text($value) . "</option>";
    }
    echo "</select>";
    echo "</td>\n";
    echo " </tr>\n";
  }
  else if ($ndc_info) {
    echo " <tr>\n";
    echo "  <td class='billcell' colspan='2'>&nbsp;</td>\n";
    echo "  <td class='billcell' colspan='6'>&nbsp;" . xlt("NDC Data") . ": " . text($ndc_info) . "</td>\n";
    echo " </tr>\n";
  }

  // For Family Planning. Increment count of Member Association Charges
  if ($codetype == 'MA') ++$required_code_count;

  if ($fee != 0) $hasCharges = true;
}

// This writes a product (drug_sales) line item to the output page.
//
function echoProdLine($lino, $drug_id, $rx = FALSE, $del = FALSE, $units = NULL,
  $fee = NULL, $sale_id = 0, $billed = FALSE, $warehouse_id = '')
{
  global $code_types, $ndc_applies, $pid, $usbillstyle, $hasCharges;
  global $required_code_count, $line_contra_code, $line_contra_cyp, $line_contra_methtype;
  global $got_warehouses, $default_warehouse;  

  $drow = sqlQuery("SELECT name FROM drugs WHERE drug_id = ?", array($drug_id) );
  $code_text = $drow['name'];

  // If no warehouse ID passed, use the logged-in user's default.
  if ($got_warehouses && $warehouse_id === '') $warehouse_id = $default_warehouse;
  
  $fee = sprintf('%01.2f', $fee);
  if (empty($units)) $units = 1;
  $units = max(1, intval($units));
  // We put unit price on the screen, not the total line item fee.
  $price = $fee / $units;
  $strike1 = ($sale_id && $del) ? "<strike>" : "";
  $strike2 = ($sale_id && $del) ? "</strike>" : "";
  echo " <tr>\n";
  echo "  <td class='billcell'>{$strike1}" . xlt("Product") . "$strike2";
  echo "<input type='hidden' name='prod[".attr($lino)."][sale_id]' value='" . attr($sale_id) . "'>";
  echo "<input type='hidden' name='prod[".attr($lino)."][drug_id]' value='" . attr($drug_id) . "'>";
  echo "<input type='hidden' name='prod[".attr($lino)."][billed]' value='" . attr($billed) . "'>";
  echo "</td>\n";
  echo "  <td class='billcell'>$strike1" . text($drug_id) . "$strike2</td>\n";
  if (modifiers_are_used(true)) {
    echo "  <td class='billcell'>&nbsp;</td>\n";
  }
  if ($billed) {
    if (fees_are_used()) {
      echo "  <td class='billcell' align='right'>" . text(oeFormatMoney($price)) . "</td>\n";
      echo "  <td class='billcell' align='center'>" . text($units) . "</td>\n";
    }
    if (justifiers_are_used()) {
      echo "  <td class='billcell' align='center'$usbillstyle>&nbsp;</td>\n"; // justify
    }

    // Generate warehouse selector if there is a choice of warehouses.
    echo "  <td class='billcell' align='center'>";
    if ($got_warehouses) {
      // Normally would use generate_select_list() but it's not flexible enough here.
      echo "<select name='prod[$lino][warehouse]'";
      echo " onchange='warehouse_changed(this);'";
      if ($sale_id) echo " disabled";
      echo ">";
      echo "<option value=''> </option>";
      $lres = sqlStatement("SELECT * FROM list_options " .
        "WHERE list_id = 'warehouse' ORDER BY seq, title");
      while ($lrow = sqlFetchArray($lres)) {
        $has_inventory = sellDrug($drug_id, 1, 0, 0, 0, 0, '', '', $lrow['option_id'], true);
        echo "<option value='" . $lrow['option_id'] . "'";
        if (((strlen($warehouse_id) == 0 && $lrow['is_default']) ||
             (strlen($warehouse_id)  > 0 && $lrow['option_id'] == $warehouse_id)) &&
            ($sale_id || $has_inventory))
        {
          echo " selected";
        }
        else {
          // Disable this warehouse option if not selected and has no inventory.
          if (!$has_inventory) { echo " disabled"; }
        }
        echo ">" . xl_list_label($lrow['title']) . "</option>\n";
      }
      echo "</select>";
    }
    else {
      echo "&nbsp;";
    }
    echo "</td>\n";

    echo "  <td class='billcell' align='center'$usbillstyle>&nbsp;</td>\n"; // auth
    if ($GLOBALS['gbl_auto_create_rx']) {
      echo "  <td class='billcell' align='center'><input type='checkbox'" . // rx
        " disabled /></td>\n";
    }    
    echo "  <td class='billcell' align='center'><input type='checkbox'" .   // del
      " disabled /></td>\n";
  } else {
    if (fees_are_used()) {
      echo "  <td class='billcell' align='right'>" .
        "<input type='text' name='prod[".attr($lino)."][price]' " .
        "value='" . attr($price) . "' size='6' onchange='setSaveAndClose()'";
      if (acl_check('acct','disc'))
        echo " style='text-align:right'";
      else
        echo " style='text-align:right;background-color:transparent' readonly";
      echo "></td>\n";
      echo "  <td class='billcell' align='center'>";
      echo "<input type='text' name='prod[".attr($lino)."][units]' " .
        "value='" . attr($units) . "' size='2' style='text-align:right'>";
      echo "</td>\n";
    }
    if (justifiers_are_used()) {
      echo "  <td class='billcell'$usbillstyle>&nbsp;</td>\n"; // justify
    }
    echo "  <td class='billcell' align='center'>&nbsp;</td>\n"; // provider
    echo "  <td class='billcell' align='center'$usbillstyle>&nbsp;</td>\n"; // auth
    if ($GLOBALS['gbl_auto_create_rx']) {
      echo "  <td class='billcell' align='center'>" .
        "<input type='checkbox' name='prod[$lino][rx]' value='1'" .
        ($rx ? " checked" : "") . " /></td>\n";
    }    
    echo "  <td class='billcell' align='center'><input type='checkbox' name='prod[".attr($lino)."][del]' " .
      "value='1'" . ($del ? " checked" : "") . " /></td>\n";
  }

  echo "  <td class='billcell'>$strike1" . text($code_text) . "$strike2</td>\n";
  echo " </tr>\n";

  if ($fee != 0) $hasCharges = true;
  
  ++$required_code_count;
}

// Build a drop-down list of providers.  This includes users who
// have the word "provider" anywhere in their "additional info"
// field, so that we can define providers (for billing purposes)
// who do not appear in the calendar.
//
function genProviderSelect($selname, $toptext, $default=0, $disabled=false) {
  // Get user's default facility, or 0 if none.
  $drow = sqlQuery("SELECT facility_id FROM users where username = '" . $_SESSION['authUser'] . "'");
  $def_facility = 0 + $drow['facility_id'];
  //
  $query = "SELECT id, lname, fname, facility_id FROM users WHERE " .
    "( authorized = 1 OR info LIKE '%provider%' ) AND username != '' " .
    "AND active = 1 AND ( info IS NULL OR info NOT LIKE '%Inactive%' )";
  // If restricting to providers matching user facility...
  if ($GLOBALS['gbl_restrict_provider_facility']) {
    $query .= " AND ( facility_id = 0 OR facility_id = $def_facility )";
    $query .= " ORDER BY lname, fname";
  }
  // If not restricting then sort the matching providers first.
  else {
    $query .= " ORDER BY (facility_id = $def_facility) DESC, lname, fname";
  }
  $res = sqlStatement($query);
  echo "   <select name='$selname'";
  if ($disabled) echo " disabled";
  echo ">\n";
  echo "    <option value=''>$toptext\n";
  while ($row = sqlFetchArray($res)) {
    $provid = $row['id'];
    echo "    <option value='$provid'";
    if ($provid == $default) echo " selected";
    echo ">";
    if (!$GLOBALS['gbl_restrict_provider_facility'] && $def_facility && $row['facility_id'] == $def_facility) {
      // Mark providers in the matching facility with an asterisk.
      echo "* ";
    }
    echo $row['lname'] . ", " . $row['fname'] . "\n";
  }
  echo "   </select>\n";
}

function insert_lbf_item($form_id, $field_id, $field_value) {
  if ($form_id) {
    sqlInsert("INSERT INTO lbf_data (form_id, field_id, field_value) " .
      "VALUES ($form_id, '$field_id', '$field_value')");
  }
  else {
    $form_id = sqlInsert("INSERT INTO lbf_data (field_id, field_value) " .
      "VALUES ('$field_id', '$field_value')");
  }
  return $form_id;
}

// These variables are used to compute the initial consult service with highest CYP.
//
$contraception_code = '';
$contraception_cyp  = 0;


// Possible units of measure for NDC drug quantities.
//
$ndc_uom_choices = array(
  'ML' => 'ML',
  'GR' => 'Grams',
  'ME' => 'Milligrams',
  'F2' => 'I.U.',
  'UN' => 'Units'
);

// $FEE_SHEET_COLUMNS should be defined in codes.php.
if (empty($FEE_SHEET_COLUMNS)) $FEE_SHEET_COLUMNS = 2;

$returnurl = $GLOBALS['concurrent_layout'] ? 'encounter_top.php' : 'patient_encounter.php';

// Update price level in patient demographics.
if (!empty($_POST['pricelevel'])) {
  sqlStatement("UPDATE patient_data SET pricelevel = ? WHERE pid = ?", array($_POST['pricelevel'],$pid) );
}

// Get some info about this visit.
$visit_row = sqlQuery("SELECT fe.date, opc.pc_catname " .
  "FROM form_encounter AS fe " .
  "LEFT JOIN openemr_postcalendar_categories AS opc ON opc.pc_catid = fe.pc_catid " .
  "WHERE fe.pid = ? AND fe.encounter = ? LIMIT 1", array($pid,$encounter) );
$visit_date = substr($visit_row['date'], 0, 10);

if (!$alertmsg && ($_POST['bn_save'] || $_POST['bn_save_close'])) {
  // Check for insufficient product inventory levels.
  $prod = $_POST['prod'];
  $insufficient = 0;
  $expiredlots = false;
  for ($lino = 1; $prod["$lino"]['drug_id']; ++$lino) {
    $iter = $prod["$lino"];
    if (!empty($iter['billed'])) continue;
    $drug_id   = $iter['drug_id'];
    $sale_id   = $iter['sale_id']; // present only if already saved
    $units     = max(1, intval(trim($iter['units'])));
    $del       = $iter['del'];
    $warehouse_id = empty($iter['warehouse']) ? '' : $iter['warehouse'];
    // Deleting always works.
    if ($del) continue;
    // If the item is already in the database...
    if ($sale_id) {
      $query = "SELECT (di.on_hand + ds.quantity - $units) AS new_on_hand " .
        "FROM drug_sales AS ds, drug_inventory AS di WHERE " .
        "ds.sale_id = '$sale_id' AND di.inventory_id = ds.inventory_id";
      $dirow = sqlQuery($query);
      if ($dirow['new_on_hand'] < 0) {
        $insufficient = $drug_id;
      }
    }
    // Otherwise it's a new item...
    else {
      // This only checks for sufficient inventory, nothing is updated.
      if (!sellDrug($drug_id, $units, 0, $pid, $encounter, 0,
        $visit_date, '', $warehouse_id, true, $expiredlots)) {
        $insufficient = $drug_id;
      }
    }
  } // end for
  if ($insufficient) {
    $drow = sqlQuery("SELECT name FROM drugs WHERE drug_id = '$insufficient'");
    $alertmsg = xl('Insufficient inventory for product') . ' "' . $drow['name'] . '".';
    if ($expiredlots) $alertmsg .= " " . xl('Check expiration dates.');
  }
}

// If Save or Save-and-Close was clicked, save the new and modified billing
// lines; then if no error, redirect to $returnurl.
//
if (!$alertmsg && ($_POST['bn_save'] || $_POST['bn_save_close'])) {
  $main_provid = 0 + $_POST['ProviderID'];
  $main_supid  = 0 + $_POST['SupervisorID'];
  if ($main_supid == $main_provid) $main_supid = 0;

  $bill = $_POST['bill'];
  $copay_update = FALSE;
  $update_session_id = '';
  $ct0 = '';//takes the code type of the first fee type code type entry from the fee sheet, against which the copay is posted
  $cod0 = '';//takes the code of the first fee type code type entry from the fee sheet, against which the copay is posted
  $mod0 = '';//takes the modifier of the first fee type code type entry from the fee sheet, against which the copay is posted
  for ($lino = 1; $bill["$lino"]['code_type']; ++$lino) {
    $iter = $bill["$lino"];
    $code_type = $iter['code_type'];
    $code      = $iter['code'];
    $del       = $iter['del'];

    // Skip disabled (billed) line items.
    if ($iter['billed']) continue;

    $id        = $iter['id'];
    $modifier  = trim($iter['mod']);
    if( !($cod0) && ($code_types[$code_type]['fee'] == 1) ){
      $mod0 = $modifier;
      $cod0 = $code;
      $ct0 = $code_type;
    }
    $units     = max(1, intval(trim($iter['units'])));
    $fee       = sprintf('%01.2f',(0 + trim($iter['price'])) * $units);
    
    if($code_type == 'COPAY'){
      if($id == ''){
        //adding new copay from fee sheet into ar_session and ar_activity tables
        if($fee < 0){
          $fee = $fee * -1;
        }
        $session_id = idSqlStatement("INSERT INTO ar_session(payer_id,user_id,pay_total,payment_type,description,".
          "patient_id,payment_method,adjustment_code,post_to_date) VALUES('0',?,?,'patient','COPAY',?,'','patient_payment',now())",
          array($_SESSION['authId'],$fee,$pid));
        SqlStatement("INSERT INTO ar_activity (pid,encounter,code_type,code,modifier,payer_type,post_time,post_user,session_id,".
          "pay_amount,account_code) VALUES (?,?,?,?,?,0,now(),?,?,?,'PCP')",
          array($pid,$encounter,$ct0,$cod0,$mod0,$_SESSION['authId'],$session_id,$fee));
      }else{
        //editing copay saved to ar_session and ar_activity
        if($fee < 0){
          $fee = $fee * -1;
        }
        $session_id = $id;
        $res_amount = sqlQuery("SELECT pay_amount FROM ar_activity WHERE pid=? AND encounter=? AND session_id=?",
          array($pid,$encounter,$session_id));
        if($fee != $res_amount['pay_amount']){
          sqlStatement("UPDATE ar_session SET user_id=?,pay_total=?,modified_time=now(),post_to_date=now() WHERE session_id=?",
            array($_SESSION['authId'],$fee,$session_id));
          sqlStatement("UPDATE ar_activity SET code_type=?, code=?, modifier=?, post_user=?, post_time=now(),".
            "pay_amount=?, modified_time=now() WHERE pid=? AND encounter=? AND account_code='PCP' AND session_id=?",
            array($ct0,$cod0,$mod0,$_SESSION['authId'],$fee,$pid,$encounter,$session_id));
        }
      }
      if(!$cod0){
        $copay_update = TRUE;
        $update_session_id = $session_id;
      }
      continue;
    }
    $justify   = trim($iter['justify']);
    $notecodes = trim($iter['notecodes']);
    if ($justify) $justify = str_replace(',', ':', $justify) . ':';
    // $auth      = $iter['auth'] ? "1" : "0";
    $auth      = "1";
    $provid    = 0 + $iter['provid'];

    $ndc_info = '';
    if ($iter['ndcnum']) {
    $ndc_info = 'N4' . trim($iter['ndcnum']) . '   ' . $iter['ndcuom'] .
      trim($iter['ndcqty']);
    }

    // If the item is already in the database...
    if ($id) {
      if ($del) {
        deleteBilling($id);
      }
      else {
        // authorizeBilling($id, $auth);
        sqlQuery("UPDATE billing SET code = ?, " .
          "units = ?, fee = ?, modifier = ?, " .
          "authorized = ?, provider_id = ?, " .
          "ndc_info = ?, justify = ?, notecodes = ? " .
          "WHERE " .
          "id = ? AND billed = 0 AND activity = 1", array($code,$units,$fee,$modifier,$auth,$provid,$ndc_info,$justify,$notecodes,$id) );
      }
    }

    // Otherwise it's a new item...
    else if (! $del) {
      $code_text = lookup_code_descriptions($code_type.":".$code);
      addBilling($encounter, $code_type, $code, $code_text, $pid, $auth,
        $provid, $modifier, $units, $fee, $ndc_info, $justify, 0, $notecodes);
    }
  } // end for
  
  //if modifier is not inserted during loop update the record using the first
  //non-empty modifier and code
  if($copay_update == TRUE && $update_session_id != '' && $mod0 != ''){
    sqlStatement("UPDATE ar_activity SET code_type=?, code=?, modifier=?".
      " WHERE pid=? AND encounter=? AND account_code='PCP' AND session_id=?",
      array($ct0,$cod0,$mod0,$pid,$encounter,$update_session_id));
  }

  // Doing similarly to the above but for products.
  $prod = $_POST['prod'];
  for ($lino = 1; $prod["$lino"]['drug_id']; ++$lino) {
    $iter = $prod["$lino"];

    if (!empty($iter['billed'])) continue;

    $drug_id   = $iter['drug_id'];
    $sale_id   = $iter['sale_id']; // present only if already saved
    $units     = max(1, intval(trim($iter['units'])));
    $fee       = sprintf('%01.2f',(0 + trim($iter['price'])) * $units);
    $del       = $iter['del'];
    $rxid      = 0;
    $warehouse_id = empty($iter['warehouse']) ? '' : $iter['warehouse'];    

    // If the item is already in the database...
    if ($sale_id) {
      $tmprow = sqlQuery("SELECT prescription_id FROM drug_sales WHERE " .
        "sale_id = '$sale_id'");
      $rxid = 0 + $tmprow['prescription_id'];

      if ($del) {
        // Zero out this sale and reverse its inventory update.  We bring in
        // drug_sales twice so that the original quantity can be referenced
        // unambiguously.
        sqlStatement("UPDATE drug_sales AS dsr, drug_sales AS ds, " .
          "drug_inventory AS di " .
          "SET di.on_hand = di.on_hand + dsr.quantity, " .
          "ds.quantity = 0, ds.fee = 0 WHERE " .
          "dsr.sale_id = ? AND ds.sale_id = dsr.sale_id AND " .
          "di.inventory_id = ds.inventory_id", array($sale_id) );
        // And delete the sale for good measure.
        sqlStatement("DELETE FROM drug_sales WHERE sale_id = ?", array($sale_id) );
        // If there was a prescription delete it also.
        if ($rxid) {
          sqlStatement("DELETE FROM prescriptions WHERE id = '$rxid'");
        }
      }
      else {
        // Modify the sale and adjust inventory accordingly.
        $query = "UPDATE drug_sales AS dsr, drug_sales AS ds, " .
          "drug_inventory AS di " .
          "SET di.on_hand = di.on_hand + dsr.quantity - " . add_escape_custom($units) . ", " .
          "ds.quantity = ?, ds.fee = ?, " .
          "ds.sale_date = ? WHERE " .
          "dsr.sale_id = ? AND ds.sale_id = dsr.sale_id AND " .
          "di.inventory_id = ds.inventory_id";
        sqlStatement($query, array($units,$fee,$visit_date,$sale_id) );
        // Delete Rx if $rxid and flag not set.
        if ($GLOBALS['gbl_auto_create_rx'] && $rxid && empty($iter['rx'])) {
          sqlStatement("DELETE FROM prescriptions WHERE id = '$rxid'");
        }
      }
    }

    // Otherwise it's a new item...
    else if (! $del) {
      $sale_id = sellDrug($drug_id, $units, $fee, $pid, $encounter, 0,
        $visit_date, '', $warehouse_id);
      if (!$sale_id) die(xlt("Insufficient inventory for product ID") . " \"" . text($drug_id) . "\".");
    }
    
   // If a prescription applies, create or update it.
    if (!empty($iter['rx']) && !$del) {
      // If an active rx already exists for this drug and date we will
      // replace it, otherwise we'll make a new one.
      if (empty($rxid)) $rxid = '';
      // Get default drug attributes.
      $drow = sqlQuery("SELECT dt.*, " .
        "d.name, d.form, d.size, d.unit, d.route, d.substitute " .
        "FROM drugs AS d, drug_templates AS dt WHERE " .
        "d.drug_id = '$drug_id' AND dt.drug_id = d.drug_id " .
        "ORDER BY dt.quantity, dt.dosage, dt.selector LIMIT 1");
      if (!empty($drow)) {
        $rxobj = new Prescription($rxid);
        $rxobj->set_patient_id($pid);
        $rxobj->set_provider_id($main_provid);
        $rxobj->set_drug_id($drug_id);
        $rxobj->set_quantity($units);
        $rxobj->set_per_refill($units);
        $rxobj->set_start_date_y(substr($visit_date,0,4));
        $rxobj->set_start_date_m(substr($visit_date,5,2));
        $rxobj->set_start_date_d(substr($visit_date,8,2));
        $rxobj->set_date_added($visit_date);
        // Remaining attributes are the drug and template defaults.
        $rxobj->set_drug($drow['name']);
        $rxobj->set_unit($drow['unit']);
        $rxobj->set_dosage($drow['dosage']);
        $rxobj->set_form($drow['form']);
        $rxobj->set_refills($drow['refills']);
        $rxobj->set_size($drow['size']);
        $rxobj->set_route($drow['route']);
        $rxobj->set_interval($drow['period']);
        $rxobj->set_substitute($drow['substitute']);
        //
        $rxobj->persist();
        // Set drug_sales.prescription_id to $rxobj->get_id().
        $rxid = 0 + $rxobj->get_id();
        sqlStatement("UPDATE drug_sales SET prescription_id = '$rxid' WHERE " .
          "sale_id = '$sale_id'");
      }
    }
    
  } // end for

  // Set the main/default service provider in the new-encounter form.
  /*******************************************************************
  sqlStatement("UPDATE forms, users SET forms.user = users.username WHERE " .
    "forms.pid = '$pid' AND forms.encounter = '$encounter' AND " .
    "forms.formdir = 'newpatient' AND users.id = '$provid'");
  *******************************************************************/
  sqlStatement("UPDATE form_encounter SET provider_id = ?, " .
    "supervisor_id = ?  WHERE " .
    "pid = ? AND encounter = ?", array($main_provid,$main_supid,$pid,$encounter) );
  
  require_once("ippf/close.php");
}

$billresult = getBillingByEncounter($pid, $encounter, "*");
?>
<html>
<head>
<?php html_header_show(); ?>
<link rel="stylesheet" href="<?php echo $css_header;?>" type="text/css">
<style>
.billcell { font-family: sans-serif; font-size: 10pt }
</style>
<script language="JavaScript">

var mypcc = '<?php echo $GLOBALS['phone_country_code'] ?>';

var diags = new Array();

<?php
if ($billresult) {
  foreach ($billresult as $iter) {
    genDiagJS($iter["code_type"], trim($iter["code"]));
  }
}
if ($_POST['bill']) {
  foreach ($_POST['bill'] as $iter) {
    if ($iter["del"]) continue; // skip if Delete was checked
    if ($iter["id"])  continue; // skip if it came from the database
    genDiagJS($iter["code_type"], $iter["code"]);
  }
}
if ($_POST['newcodes']) {
  $arrcodes = explode('~', $_POST['newcodes']);
  foreach ($arrcodes as $codestring) {
    if ($codestring === '') continue;
    $arrcode = explode('|', $codestring);
    list($code, $modifier) = explode(":", $arrcode[1]);
    genDiagJS($arrcode[0], $code);
  }
}
?>

// This is invoked by <select onchange> for the various dropdowns,
// including search results.
function codeselect(selobj) {
 var i = selobj.selectedIndex;
 if (i > 0) {
  top.restoreSession();
  var f = document.forms[0];
  f.newcodes.value = selobj.options[i].value;
  f.submit();
 }
}

function copayselect() {
 top.restoreSession();
 var f = document.forms[0];
 f.newcodes.value = 'COPAY||';
 f.submit();
}

function validate(f) {
 var refreshing = f.bn_refresh.clicked ? true : false;
 var searching  = f.bn_search.clicked  ? true : false;
 f.bn_refresh.clicked = false;
 f.bn_search.clicked = false;
 var max_contra_cyp = 0;
 var max_contra_code = '';
 // Loop thru the services.    
 for (var lino = 1; f['bill['+lino+'][code_type]']; ++lino) {
  var pfx = 'bill['+lino+']';
  if (f[pfx+'[ndcnum]'] && f[pfx+'[ndcnum]'].value) {
   // Check NDC number format.
   var ndcok = true;
   var ndc = f[pfx+'[ndcnum]'].value;
   var a = ndc.split('-');
   if (a.length != 3) {
    ndcok = false;
   }
   else if (a[0].length < 1 || a[1].length < 1 || a[2].length < 1 ||
    a[0].length > 5 || a[1].length > 4 || a[2].length > 2) {
    ndcok = false;
   }
   else {
    for (var i = 0; i < 3; ++i) {
     for (var j = 0; j < a[i].length; ++j) {
      var c = a[i].charAt(j);
      if (c < '0' || c > '9') ndcok = false;
     }
    }
   }
   if (!ndcok) {
    alert('<?php xl('Format incorrect for NDC','e') ?> "' + ndc +
     '", <?php xl('should be like nnnnn-nnnn-nn','e') ?>');
    if (f[pfx+'[ndcnum]'].focus) f[pfx+'[ndcnum]'].focus();
    return false;
   }
   // Check for valid quantity.
   var qty = f[pfx+'[ndcqty]'].value - 0;
   if (isNaN(qty) || qty <= 0) {
    alert('<?php xl('Quantity for NDC','e') ?> "' + ndc +
     '" <?php xl('is not valid (decimal fractions are OK).','e') ?>');
    if (f[pfx+'[ndcqty]'].focus) f[pfx+'[ndcqty]'].focus();
    return false;
   }
  }
  if (f[pfx+'[method]'] && f[pfx+'[method]'].value) {
   // The following applies to contraception for family planning clinics.
   var tmp_cyp = parseFloat(f[pfx+'[cyp]'].value);
   var tmp_meth = f[pfx+'[method]'].value;
   var tmp_methtype = parseInt(f[pfx+'[methtype]'].value);
   if (tmp_cyp > max_contra_cyp && tmp_methtype == 2) {
    // max_contra_* tracks max cyp for initial consults only.
    max_contra_cyp = tmp_cyp;
    max_contra_code = tmp_meth;
   }
<?php if ($patient_male) { ?>
   var tmp = tmp_meth.substring(0, 6);
   if (tmp != '112141' // male condoms
    && tmp != '122182' // male vasectomy
    && tmp != '141200' // fp general counseling
   ) {
    if (!confirm('<?php echo xl('Warning: Contraceptive method is not compatible with a male patient.'); ?>'))
     return false;
   }
<?php } // end if male patient ?>
<?php if ($patient_age < 10 || patient_age > 50) { ?>
   if (!confirm('<?php echo xl('Warning: Contraception for a patient under 10 or over 50.'); ?>'))
    return false;
<?php } // end if improper age ?>
<?php if ($match_services_to_products) { ?>
   // Nonsurgical methods should normally include a corresponding product.
   if (tmp_meth.substring(0, 2) != '12') {
    var got_prod = false;
    for (var plino = 1; f['prod['+plino+'][drug_id]']; ++plino) {
     var ppfx = 'prod[' + plino + ']';
     if (f[ppfx+'[del]'] && f[ppfx+'[del]'].checked) continue;
     if (f[ppfx+'[method]'] && f[ppfx+'[method]'].value) {
      if (f[ppfx+'[method]'].value == tmp_meth) got_prod = true;
     }
    }
    if (!got_prod) {
     if (!confirm('<?php echo xl('Warning: There is no product matching the contraceptive service.'); ?>'))
      return false;
    }
   }
<?php } // end match services to products ?>
  }
  // End contraception validation.
 }
<?php if ($match_services_to_products) { ?>
 // The following applies to contraception for family planning clinics.
 // Loop thru the products.
 for (var lino = 1; f['prod['+lino+'][drug_id]']; ++lino) {
  var pfx = 'prod['+lino+']';
  if (f[pfx+'[del]'] && f[pfx+'[del]'].checked) continue;
  if (f[pfx+'[method]'] && f[pfx+'[method]'].value) {
   var tmp_meth = f[pfx+'[method]'].value;
   // Contraceptive products should normally include a corresponding method.
   var got_svc = false;
   for (var slino = 1; f['bill['+slino+'][code_type]']; ++slino) {
    var spfx = 'bill[' + slino + ']';
    if (f[spfx+'[del]'] && f[spfx+'[del]'].checked) continue;
    if (f[spfx+'[method]'] && f[spfx+'[method]'].value) {
     if (f[spfx+'[method]'].value == tmp_meth) got_svc = true;
    }
   }
   if (!got_svc) {
    if (!confirm('<?php echo xl('Warning: There is no service matching the contraceptive product.'); ?>'))
     return false;
   }
  }
 }
<?php } // end match services to products ?>
 // End contraception validation.
 if (!refreshing && !searching) {
  if (!f.ProviderID.value) {
   alert('<?php echo xl('Default provider is required.') ?>');
   return false;
  }
<?php if (isset($code_types['MA'])) { ?>
  if (required_code_count == 0) {
   if (!confirm('<?php echo xl('You have not entered any clinical services or products.' .
    ' Click Cancel to add them. Or click OK if you want to save as-is.') ?>')) {
    return false;
   }
  }
<?php } ?>
 }
 if (f.ippfconmeth) {
  f.ippfconmeth.value = max_contra_code;
  // alert('ippfconmeth set to ' + max_contra_code); // debugging
 }
 top.restoreSession();
 return true;
}

// When a justify selection is made, apply it to the current list for
// this procedure and then rebuild its selection list.
//
function setJustify(seljust) {
 var theopts = seljust.options;
 var jdisplay = theopts[0].text;
 // Compute revised justification string.  Note this does nothing if
 // the first entry is still selected, which is handy at startup.
 if (seljust.selectedIndex > 0) {
  var newdiag = seljust.value;
  if (newdiag.length == 0) {
   jdisplay = '';
  }
  else {
   if (jdisplay.length) jdisplay += ',';
   jdisplay += newdiag;
  }
 }
 // Rebuild selection list.
 var jhaystack = ',' + jdisplay + ',';
 var j = 0;
 theopts.length = 0;
 theopts[j++] = new Option(jdisplay,jdisplay,true,true);
 for (var i = 0; i < diags.length; ++i) {
  if (jhaystack.indexOf(',' + diags[i] + ',') < 0) {
   theopts[j++] = new Option(diags[i],diags[i],false,false);
  }
 }
 theopts[j++] = new Option('Clear','',false,false);
}

// Function to check if there are any charges in the form, and to enable
// or disable the Save and Close button accordingly.
//
function setSaveAndClose() {
 var f = document.forms[0];
 if (!f.bn_save_close) return;
 var hascharges = false;
 for (var i = 0; i < f.elements.length; ++i) {
  var elem = f.elements[i];
  if (elem.name.indexOf('[price]') > 0) {
   var fee = Number(elem.value);
   // alert('Fee is "' + fee + '"'); // debugging
   if (!isNaN(fee) && fee != 0) hascharges = true;
  }
 }
 
 // f.bn_save_close.disabled = hascharges;
 if (hascharges) {
  f.form_has_charges.value = '1';
  f.bn_save_close.value = '<?php echo xl('Save and Checkout'); ?>';
 }
 else {
  f.form_has_charges.value = '0';
  f.bn_save_close.value = '<?php echo xl('Save and Close'); ?>';
 }
}

function warehouse_changed(sel) {
 if (!confirm('<?php echo xl('Do you really want to change Warehouse?'); ?>')) {
  // They clicked Cancel so reset selection to its default state.
  for (var i = 0; i < sel.options.length; ++i) {
   sel.options[i].selected = sel.options[i].defaultSelected;
  }
 }
}

</script>
</head>

<body class="body_top">
<form method="post" action="<?php echo $rootdir; ?>/forms/fee_sheet/new.php?rde=<?php echo $rapid_data_entry; ?>"
 onsubmit="return validate(this)">
<span class="title"><?php echo xlt('Fee Sheet'); ?></span><br>
<input type='hidden' name='newcodes' value=''>

<center>

<?php
$isBilled = isEncounterBilled($pid, $encounter);
if ($isBilled) {
  echo "<p><font color='green'>" . xlt("This encounter has been billed. If you need to change it, it must be re-opened.") . "</font></p>\n";
}
else { // the encounter is not yet billed
?>

<table width='95%'>
<?php
$i = 0;
$last_category = '';

// Create drop-lists based on the fee_sheet_options table.
$res = sqlStatement("SELECT * FROM fee_sheet_options " .
  "ORDER BY fs_category, fs_option");
while ($row = sqlFetchArray($res)) {
  $fs_category = $row['fs_category'];
  $fs_option   = $row['fs_option'];
  $fs_codes    = $row['fs_codes'];
  if($fs_category !== $last_category) {
    endFSCategory();
    $last_category = $fs_category;
    ++$i;
    echo ($i <= 1) ? " <tr>\n" : "";
    echo "  <td width='50%' align='center' nowrap>\n";
    echo "   <select style='width:96%' onchange='codeselect(this)'>\n";
    echo "    <option value=''> " . text(substr($fs_category, 1)) . "</option>\n";
  }
  echo "    <option value='" . attr($fs_codes) . "'>" . text(substr($fs_option, 1)) . "</option>\n";
}
endFSCategory();

// Create drop-lists based on categories defined within the codes.
$pres = sqlStatement("SELECT option_id, title FROM list_options " .
  "WHERE list_id = 'superbill' ORDER BY seq");
while ($prow = sqlFetchArray($pres)) {
  global $code_types;
  ++$i;
  echo ($i <= 1) ? " <tr>\n" : "";
  echo "  <td width='50%' align='center' nowrap>\n";
  echo "   <select style='width:96%' onchange='codeselect(this)'>\n";
  echo "    <option value=''> " . text($prow['title']) . "\n";
  $res = sqlStatement("SELECT code_type, code, code_text,modifier FROM codes " .
    "WHERE superbill = ? AND active = 1 " .
    "ORDER BY code_text", array($prow['option_id']) );
  while ($row = sqlFetchArray($res)) {
    $ctkey = alphaCodeType($row['code_type']);
    if ($code_types[$ctkey]['nofs']) continue;
    echo "    <option value='" . attr($ctkey) . "|" .
      attr($row['code']) . ':'. attr($row['modifier']) . "|'>" . text($row['code_text']) . "</option>\n";
  }
  echo "   </select>\n";
  echo "  </td>\n";
  if ($i >= $FEE_SHEET_COLUMNS) {
    echo " </tr>\n";
    $i = 0;
  }
}

// Create one more drop-list, for Products.
if ($GLOBALS['sell_non_drug_products']) {
  ++$i;
  echo ($i <= 1) ? " <tr>\n" : "";
  echo "  <td width='50%' align='center' nowrap>\n";
  echo "   <select name='Products' style='width:96%' onchange='codeselect(this)'>\n";
  echo "    <option value=''> " . xlt('Products') . "\n";
  $tres = sqlStatement("SELECT dt.drug_id, dt.selector, d.name " .
    "FROM drug_templates AS dt, drugs AS d WHERE " .
    "d.drug_id = dt.drug_id AND d.active = 1 " .
    "ORDER BY d.name, dt.selector, dt.drug_id");
  while ($trow = sqlFetchArray($tres)) {
    echo "    <option value='PROD|" . attr($trow['drug_id']) . '|' . attr($trow['selector']) . "'>" .
      text($trow['drug_id']) . ':' . text($trow['selector']);
    if ($trow['name'] !== $trow['selector']) echo ' ' . text($trow['name']);
    echo "</option>\n";
  }
  echo "   </select>\n";
  echo "  </td>\n";
  if ($i >= $FEE_SHEET_COLUMNS) {
    echo " </tr>\n";
    $i = 0;
  }
}

$search_type = $default_search_type;
if ($_POST['search_type']) $search_type = $_POST['search_type'];

$ndc_applies = true; // Assume all payers require NDC info.

echo $i ? "  <td></td>\n </tr>\n" : "";
echo " <tr>\n";
echo "  <td colspan='" . attr($FEE_SHEET_COLUMNS) . "' align='center' nowrap>\n";

// If Search was clicked, do it and write the list of results here.
// There's no limit on the number of results!
//
$numrows = 0;
if ($_POST['bn_search'] && $_POST['search_term']) {
  $res = main_code_set_search($search_type,$_POST['search_term']);
  if (!empty($res)) {
    $numrows = sqlNumRows($res);
  }
}

echo "   <select name='Search Results' style='width:98%' " .
  "onchange='codeselect(this)'";
if (! $numrows) echo ' disabled';
echo ">\n";
echo "    <option value=''> " . xlt("Search Results") . " ($numrows " . xlt("items") . ")\n";

if ($numrows) {
  while ($row = sqlFetchArray($res)) {
    $code = $row['code'];
    if ($row['modifier']) $code .= ":" . $row['modifier'];
    echo "    <option value='" . attr($search_type) . "|" . attr($code) . "|'>" . text($code) . " " .
      text($row['code_text']) . "</option>\n";
  }
}

echo "   </select>\n";
echo "  </td>\n";
echo " </tr>\n";
?>

</table>

<p style='margin-top:8px;margin-bottom:8px'>
<table>
 <tr>
  <?php if($ALLOW_COPAY) {  ?>
  <td>
   <input type='button' value='<?php echo xla('Add Copay');?>'
    onclick="copayselect()" />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
  </td>
  <?php } ?>
  <td>
   <?php echo xlt('Search'); ?>&nbsp;
  </td>
  <td>
<?php
  $nofs_code_types = array();
  foreach ($code_types as $key => $value) {
    if (!empty($value['nofs'])) continue;
    $nofs_code_types[$key] = $value;
  }
  $size_select = (count($nofs_code_types) < 5) ? count($nofs_code_types) : 5;
?>
  <select name='search_type' size='<?php echo attr($size_select) ?>'>
<?php
  foreach ($nofs_code_types as $key => $value) {
    echo "   <option value='" . attr($key) . "'";
    if ($key == $default_search_type) echo " selected";
    echo " />" . xlt($value['label']) . "</option>";
  }
?>
  </select>
  </td>
  <td>
   <?php echo xlt('for'); ?>&nbsp;
  </td>
  <td>
   <input type='text' name='search_term' value=''> &nbsp;
  </td>
  <td>
   <input type='submit' name='bn_search' onclick='return this.clicked = true;'
    value='<?php echo xla('Search');?>'>
  </td>
 </tr>
</table>
</p>
<p style='margin-top:16px;margin-bottom:8px'>

<?php } // end encounter not billed ?>

<table cellspacing='5'>
 <tr>
  <td class='billcell'><b><?php echo xlt('Type');?></b></td>
  <td class='billcell'><b><?php echo xlt('Code');?></b></td>
<?php if (modifiers_are_used(true)) { ?>
  <td class='billcell'><b><?php echo xlt('Modifiers');?></b></td>
<?php } ?>
<?php if (fees_are_used()) { ?>
  <td class='billcell' align='right'><b><?php echo xlt('Price');?></b>&nbsp;</td>
  <td class='billcell' align='center'><b><?php echo xlt('Units');?></b></td>
<?php } ?>
<?php if (justifiers_are_used()) { ?>
  <td class='billcell' align='center'<?php echo $usbillstyle; ?>><b><?php echo xlt('Justify');?></b></td>
<?php } ?>
  <td class='billcell' align='center'><b><?php echo xlt('Provider/Warehouse');?></b></td>
  <td class='billcell' align='center'<?php echo $usbillstyle; ?>><b><?php echo xlt('Note Codes');?></b></td>
  <td class='billcell' align='center'<?php echo $usbillstyle; ?>><b><?php echo xlt('Auth');?></b></td>
<?php if ($GLOBALS['gbl_auto_create_rx']) { ?>
  <td class='billcell' align='center'><b><?php echo xlt('Rx');?></b></td>
<?php } ?>  
  <td class='billcell' align='center'><b><?php echo xlt('Delete');?></b></td>
  <td class='billcell'><b><?php echo xlt('Description');?></b></td>
 </tr>

<?php
$justinit = "var f = document.forms[0];\n";

// $encounter_provid = -1;

$hasCharges = false;
$required_code_count = 0;

// Generate lines for items already in the billing table for this encounter,
// and also set the rendering provider if we come across one.
//
$bill_lino = 0;
if ($billresult) {
  foreach ($billresult as $iter) {
    // Skip copay lines if disabled
    if (!$ALLOW_COPAYS && $iter["code_type"] == 'COPAY') continue;      
    ++$bill_lino;
    $bline = $_POST['bill']["$bill_lino"];
    $del = $bline['del']; // preserve Delete if checked

    $modifier   = trim($iter["modifier"]);
    $units      = $iter["units"];
    $fee        = $iter["fee"];
    $authorized = $iter["authorized"];
    $ndc_info   = $iter["ndc_info"];
    $justify    = trim($iter['justify']);
    $notecodes  = trim($iter['notecodes']);
    if ($justify) $justify = substr(str_replace(':', ',', $justify), 0, strlen($justify) - 1);
    $provider_id = $iter['provider_id'];

    // Also preserve other items from the form, if present.
    if ($bline['id'] && !$iter["billed"]) {
      $modifier   = trim($bline['mod']);
      $units      = max(1, intval(trim($bline['units'])));
      $fee        = sprintf('%01.2f',(0 + trim($bline['price'])) * $units);
      $authorized = $bline['auth'];
      $ndc_info   = '';
      if ($bline['ndcnum']) {
        $ndc_info = 'N4' . trim($bline['ndcnum']) . '   ' . $bline['ndcuom'] .
        trim($bline['ndcqty']);
      }
      $justify    = $bline['justify'];
      $notecodes  = trim($bline['notecodes']);
      $provider_id = 0 + $bline['provid'];
    }
    
    if($iter['code_type'] == 'COPAY'){//moved copay display to below
      --$bill_lino;
      continue;
    }
    
    // list($code, $modifier) = explode("-", $iter["code"]);
    echoLine($bill_lino, $iter["code_type"], trim($iter["code"]),
      $modifier, $ndc_info,  $authorized,
      $del, $units, $fee, $iter["id"], $iter["billed"],
      $iter["code_text"], $justify, $provider_id, $notecodes);
  }
}

$resMoneyGot = sqlStatement("SELECT pay_amount as PatientPay,session_id as id,date(post_time) as date ".
  "FROM ar_activity where pid =? and encounter =? and payer_type=0 and account_code='PCP'",
  array($pid,$encounter));//new fees screen copay gives account_code='PCP'
while($rowMoneyGot = sqlFetchArray($resMoneyGot)){
  $PatientPay=$rowMoneyGot['PatientPay']*-1;
  $id=$rowMoneyGot['id'];
  echoLine(++$bill_lino,'COPAY','','',$rowMoneyGot['date'],'1','','',$PatientPay,$id);
}

// Echo new billing items from this form here, but omit any line
// whose Delete checkbox is checked.
//
if ($_POST['bill']) {
  foreach ($_POST['bill'] as $key => $iter) {
    if ($iter["id"])  continue; // skip if it came from the database
    if ($iter["del"]) continue; // skip if Delete was checked
    $ndc_info = '';
    if ($iter['ndcnum']) {
      $ndc_info = 'N4' . trim($iter['ndcnum']) . '   ' . $iter['ndcuom'] .
      trim($iter['ndcqty']);
    }
    // $fee = 0 + trim($iter['fee']);
    $units = max(1, intval(trim($iter['units'])));
    $fee = sprintf('%01.2f',(0 + trim($iter['price'])) * $units);
    //the date is passed as $ndc_info, since this variable is not applicable in the case of copay.
    $ndc_info = '';
    if ($iter['code_type'] == 'COPAY'){
      $ndc_info = date("Y-m-d");
      if($fee > 0)
      $fee = 0 - $fee;
    }
    echoLine(++$bill_lino, $iter["code_type"], $iter["code"], trim($iter["mod"]),
      $ndc_info, $iter["auth"], $iter["del"], $units,
      $fee, NULL, FALSE, NULL, $iter["justify"], 0 + $iter['provid'],
      $iter['notecodes']);
  }
}

// Generate lines for items already in the drug_sales table for this encounter.
//
$query = "SELECT ds.*, di.warehouse_id FROM drug_sales AS ds, drug_inventory AS di WHERE " .
  "ds.pid = ? AND ds.encounter = ? AND di.inventory_id = ds.inventory_id " .
  "ORDER BY ds.sale_id";
$sres = sqlStatement($query, array($pid,$encounter) );
$prod_lino = 0;
while ($srow = sqlFetchArray($sres)) {
  ++$prod_lino;
  $pline = $_POST['prod']["$prod_lino"];
  $rx    = !empty($srow['prescription_id']);  
  $del   = $pline['del']; // preserve Delete if checked
  $sale_id = $srow['sale_id'];
  $drug_id = $srow['drug_id'];
  $units   = $srow['quantity'];
  $fee     = $srow['fee'];
  $billed  = $srow['billed'];
  $warehouse_id  = $srow['warehouse_id'];  
  // Also preserve other items from the form, if present and unbilled.
  if ($pline['sale_id'] && !$srow['billed']) {
    // $units      = trim($pline['units']);
    // $fee        = trim($pline['fee']);
    $units = max(1, intval(trim($pline['units'])));
    $fee   = sprintf('%01.2f',(0 + trim($pline['price'])) * $units);
    $rx    = !empty($pline['rx']);    
  }
  echoProdLine($prod_lino, $drug_id, $rx, $del, $units, $fee, $sale_id, $billed, $warehouse_id);
}

// Echo new product items from this form here, but omit any line
// whose Delete checkbox is checked.
//
if ($_POST['prod']) {
  foreach ($_POST['prod'] as $key => $iter) {
    if ($iter["sale_id"])  continue; // skip if it came from the database
    if ($iter["del"]) continue; // skip if Delete was checked
    // $fee = 0 + trim($iter['fee']);
    $units = max(1, intval(trim($iter['units'])));
    $fee   = sprintf('%01.2f',(0 + trim($iter['price'])) * $units);
    $rx    = !empty($iter['rx']); // preserve Rx if checked    
    $warehouse_id = empty($iter['warehouse_id']) ? '' : $iter['warehouse_id'];
    echoProdLine(++$prod_lino, $iter['drug_id'], FALSE, $units, $fee, 0, FALSE, $warehouse_id);
  }
}

// If new billing code(s) were <select>ed, add their line(s) here.
//
if ($_POST['newcodes']) {
  $arrcodes = explode('~', $_POST['newcodes']);
  foreach ($arrcodes as $codestring) {
    if ($codestring === '') continue;
    $arrcode = explode('|', $codestring);
    $newtype = $arrcode[0];
    $newcode = $arrcode[1];
    $newsel  = $arrcode[2];
    if ($newtype == 'COPAY') {
      $tmp = sqlQuery("SELECT copay FROM insurance_data WHERE pid = ? " .
        "AND type = 'primary' ORDER BY date DESC LIMIT 1", array($pid) );
      $code = sprintf('%01.2f', 0 + $tmp['copay']);
      echoLine(++$bill_lino, $newtype, $code, '', date("Y-m-d"), '1', '0', '1',
        sprintf('%01.2f', 0 - $code));
    }
    else if ($newtype == 'PROD') {
      $result = sqlQuery("SELECT dt.quantity, d.route " .
        "FROM drug_templates AS dt, drugs AS d WHERE " .
        "dt.drug_id = '$newcode' AND dt.selector = '$newsel' AND " .
        "d.drug_id = dt.drug_id");
      $units = max(1, intval($result['quantity']));
      // By default create a prescription if drug route is set.
      $rx = !empty($result['route']);
      $prrow = sqlQuery("SELECT prices.pr_price " .
        "FROM patient_data, prices WHERE " .
        "patient_data.pid = '$pid' AND " .
        "prices.pr_id = '$newcode' AND " .
        "prices.pr_selector = '$newsel' AND " .
        "prices.pr_level = patient_data.pricelevel " .
        "LIMIT 1");
      $fee = empty($prrow) ? 0 : $prrow['pr_price'];
      echoProdLine(++$prod_lino, $newcode, $rx, FALSE, $units, $fee);
    }
    else {
      list($code, $modifier) = explode(":", $newcode);
      $ndc_info = '';
      // If HCPCS, find last NDC string used for this code.
      if ($newtype == 'HCPCS' && $ndc_applies) {
        $tmp = sqlQuery("SELECT ndc_info FROM billing WHERE " .
          "code_type = ? AND code = ? AND ndc_info LIKE 'N4%' " .
          "ORDER BY date DESC LIMIT 1", array($newtype,$code) );
        if (!empty($tmp)) $ndc_info = $tmp['ndc_info'];
      }
      echoLine(++$bill_lino, $newtype, $code, trim($modifier), $ndc_info);
    }
  }
}

$tmp = sqlQuery("SELECT provider_id, supervisor_id FROM form_encounter " .
  "WHERE pid = ? AND encounter = ? " .
  "ORDER BY id DESC LIMIT 1", array($pid,$encounter) );
$encounter_provid = 0 + $tmp['provider_id'];
$encounter_supid  = 0 + $tmp['supervisor_id'];
?>
</table>
</p>

<br />
&nbsp;

<?php
// Choose rendering and supervising providers.
echo "<span class='billcell'><b>\n";
echo xlt('Providers') . ": &nbsp;";

echo "&nbsp;&nbsp;" . xlt('Rendering') . "\n";
genProviderSelect('ProviderID', '-- '.xl("Please Select").' --', $encounter_provid, $isBilled);

if (!$GLOBALS['ippf_specific']) {
  echo "&nbsp;&nbsp;" . xlt('Supervising') . "\n";
  genProviderSelect('SupervisorID', '-- '.xl("N/A").' --', $encounter_supid, $isBilled);
}

add_appointment_button();
echo "</b></span>\n";
?>

<p>
&nbsp;

<?php
// If applicable, ask for the contraceptive services start date.
$trow = sqlQuery("SELECT count(*) AS count FROM layout_options WHERE " .
  "form_id = 'DEM' AND field_id = 'contrastart' AND uor > 0");
if ($trow['count'] && $contraception && !$isBilled) {
  $date1 = substr($visit_row['date'], 0, 10);
  // If admission or surgical, then force contrastart.
  if ($contraception > 1 ||
    strpos(strtolower($visit_row['pc_catname']), 'admission') !== false)
  {
    echo "   <input type='hidden' name='contrastart' value='" . attr($date1) . "' />\n";
  }
  else {
    // echo "<!-- contraception = $contraception -->\n"; // debugging
    $trow = sqlQuery("SELECT contrastart " .
      "FROM patient_data WHERE " .
      "pid = ? LIMIT 1", array($pid) );
    if (empty($trow['contrastart']) || substr($trow['contrastart'], 0, 4) == '0000') {
      $date0 = date('Y-m-d', strtotime($date1) - (60 * 60 * 24));
      echo "   <select name='contrastart'>\n";
      echo "    <option value='" . attr($date1) . "'>" . xlt('This visit begins new contraceptive use') . "</option>\n";
      echo "    <option value='" . attr($date0) . "'>" . xlt('Contraceptive services previously started') . "</option>\n";
      echo "    <option value=''>" . xlt('None of the above') . "</option>\n";
      echo "   </select>\n";
      echo "&nbsp; &nbsp; &nbsp;\n";
    }
  }
}

// Following removed as warehouse choice is now at the line item level.
//
/*********************************************************************
// If there is a choice of warehouses, allow override of user default.
if ($prod_lino > 0) { // if any products are in this form
  $trow = sqlQuery("SELECT count(*) AS count FROM list_options WHERE list_id = 'warehouse'");
  if ($trow['count'] > 1) {
    $trow = sqlQuery("SELECT default_warehouse FROM users WHERE username = '" .
      $_SESSION['authUser'] . "'");
    echo "   <span class='billcell'><b>" . xl('Warehouse') . ":</b></span>\n";
    echo generate_select_list('default_warehouse', 'warehouse',
      $trow['default_warehouse'], '', ' ', '', 'warehouse_changed(this);');
    echo "&nbsp; &nbsp; &nbsp;\n";
  }
}
*********************************************************************/

// Allow the patient price level to be fixed here.
$plres = sqlStatement("SELECT option_id, title FROM list_options " .
  "WHERE list_id = 'pricelevel' ORDER BY seq");
if (true) {
  $trow = sqlQuery("SELECT pricelevel FROM patient_data WHERE " .
    "pid = ? LIMIT 1", array($pid) );
  $pricelevel = $trow['pricelevel'];
  echo "   <span class='billcell'><b>" . xlt('Price Level') . ":</b></span>\n";
  echo "   <select name='pricelevel'";
  if ($isBilled) echo " disabled";
  echo ">\n";
  while ($plrow = sqlFetchArray($plres)) {
    $key = $plrow['option_id'];
    $val = $plrow['title'];
    echo "    <option value='" . attr($key) . "'";
    if ($key == $pricelevel) echo ' selected';
    echo ">" . text($val) . "</option>\n";
  }
  echo "   </select>\n";
}
?>

&nbsp; &nbsp; &nbsp;

<?php if (!$isBilled) { ?>
<input type='submit' name='bn_save' value='<?php echo xla('Save');?>'
<?php if ($rapid_data_entry) echo " style='background-color:#cc0000';color:#ffffff'"; ?>
/>
&nbsp;
<input type='submit' name='bn_save_close' value='<?php echo xla('Mark as Billed');?>' />
&nbsp;
<input type='submit' name='bn_refresh' onclick='return this.clicked = true;'
  value='<?php echo xla('Refresh');?>'>
&nbsp;
<?php } ?>
<input type='hidden' name='form_has_charges' value='<?php echo $hasCharges ? 1 : 0; ?>' />

<input type='button' value='<?php echo xla('Cancel');?>'
 onclick="top.restoreSession();location='<?php echo "$rootdir/patient_file/encounter/$returnurl" ?>'" />

<?php if ($code_types['UCSMC']) { ?>
<p style='font-family:sans-serif;font-size:8pt;color:#666666;'>
&nbsp;<br>
<?php echo xlt('UCSMC codes provided by the University of Calgary Sports Medicine Centre');?>
</p>
<?php } ?>

</center>

</form>

<?php
// TBD: If $alertmsg, display it with a JavaScript alert().
?>

<script language='JavaScript'>
var required_code_count = <?php echo $required_code_count; ?>;
setSaveAndClose();

<?php
echo $justinit;
if ($alertmsg) {
  echo "alert('" . addslashes($alertmsg) . "');\n";
}
?>

</script>
</body>
</html>
<?php require_once("review/initialize_review.php"); ?>
