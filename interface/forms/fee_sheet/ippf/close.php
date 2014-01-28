<?php
  
  // Save-and-Close is currently specific to Family Planning but might be more
  // generally useful.  It provides the ability to mark an encounter as billed
  // directly from the Fee Sheet, if there are no charges.
  if ($_POST['bn_save_close'] && !$_POST['form_has_charges']) {
    $tmp1 = sqlQuery("SELECT SUM(ABS(fee)) AS sum FROM drug_sales WHERE " .
      "pid = '$pid' AND encounter = '$encounter'");
    $tmp2 = sqlQuery("SELECT SUM(ABS(fee)) AS sum FROM billing WHERE " .
      "pid = '$pid' AND encounter = '$encounter' AND billed = 0 AND " .
      "activity = 1");
    if ($tmp1['sum'] + $tmp2['sum'] == 0) {
      sqlStatement("update drug_sales SET billed = 1 WHERE " .
        "pid = '$pid' AND encounter = '$encounter' AND billed = 0");
      sqlStatement("UPDATE billing SET billed = 1, bill_date = NOW() WHERE " .
        "pid = '$pid' AND encounter = '$encounter' AND billed = 0 AND " .
        "activity = 1");
    }
    else {
      // Would be good to display an error message here... they clicked
      // Save and Close but the close could not be done.  However the
      // framework does not provide an easy way to do that.
    }
  }

  // Note: Taxes are computed at checkout time (in pos_checkout.php which
  // also posts to SL).  Currently taxes with insurance claims make no sense,
  // so for now we'll ignore tax computation in the insurance billing logic.

  // If appropriate, update the status of the related appointment to
  // "In exam room".
  updateAppointmentStatus($pid, $visit_date, '<');

  // More Family Planning stuff.
  if (isset($_POST['ippfconmeth'])) {
    $csrow = sqlQuery("SELECT f.form_id, ld.field_value FROM forms AS f " .
      "LEFT JOIN lbf_data AS ld ON ld.form_id = f.form_id AND ld.field_id = 'newmethod' " .
      "WHERE " .
      "f.pid = '$pid' AND f.encounter = '$encounter' AND " .
      "f.formdir = 'LBFccicon' AND f.deleted = 0 " .
      "ORDER BY f.form_id DESC LIMIT 1");
    if (isset($_POST['newmauser'])) {
      $newmauser   = $_POST['newmauser'];
      $ippfconmeth = $_POST['ippfconmeth'];
      // Add contraception form but only if it does not already exist
      // (if it does, must be 2 users working on the visit concurrently).
      if (empty($csrow)) {
        $newid = insert_lbf_item(0, 'newmauser', $newmauser);
        insert_lbf_item($newid, 'newmethod', $ippfconmeth);
        // Do we care about a service-specific provider here?
        insert_lbf_item($newid, 'provider', $main_provid);
        addForm($encounter, 'Contraception', $newid, 'LBFccicon', $pid, $userauthorized);
      }
    }
    else if (empty($csrow) || $csrow['field_value'] != $ippfconmeth) {
      // Contraceptive method does not match what is in an existing Contraception
      // form for this visit, or there is no such form.  Open the form.
      formJump("{$GLOBALS['rootdir']}/patient_file/encounter/view_form.php" .
        "?formname=LBFccicon&id=" . (empty($csrow) ? 0 : $csrow['form_id']));
      formFooter();
      exit;
    }
  }

  if ($rapid_data_entry || ($_POST['bn_save_close'] && $_POST['form_has_charges'])) {
    // In rapid data entry mode or if "Save and Checkout" was clicked,
    // we go directly to the Checkout page.
    formJump("{$GLOBALS['rootdir']}/patient_file/pos_checkout.php?framed=1&rde=$rapid_data_entry");
  }
  else {
    // Otherwise return to the normal encounter summary frameset.
    formHeader("Redirecting....");
    formJump();
  }
  formFooter();
  exit;

?>