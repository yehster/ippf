<?php

function checkRelatedForContraception($related_code) {
  global $line_contra_code, $line_contra_cyp, $line_contra_methtype;

  $line_contra_code     = '';
  $line_contra_cyp      = 0;
  $line_contra_methtype = 0; // 0 = None, 1 = Not initial, 2 = Initial consult

  if (!empty($related_code)) {
    $relcodes = explode(';', $related_code);
    foreach ($relcodes as $relstring) {
      if ($relstring === '') continue;
      list($reltype, $relcode) = explode(':', $relstring);
      if ($reltype !== 'IPPF') continue;
      $methtype = 1;
      if (
        preg_match('/^11....110/'    , $relcode) ||
        preg_match('/^11...[1-5]999/', $relcode) ||
        preg_match('/^112152010/'    , $relcode) ||
        preg_match('/^12118[1-2].13/', $relcode) ||
        preg_match('/^121181999/'    , $relcode) ||
        preg_match('/^122182.13/'    , $relcode) ||
        preg_match('/^122182999/'    , $relcode) ||
        preg_match('/^145212.10/'    , $relcode) ||
        preg_match('/^14521.999/'    , $relcode)
      ) {
        $methtype = 2;
      }
      $tmprow = sqlQuery("SELECT cyp_factor FROM codes WHERE " .
        "code_type = '11' AND code = '$relcode' LIMIT 1");
      $cyp = 0 + $tmprow['cyp_factor'];
      if ($cyp > $line_contra_cyp) {
        // If surgical
        if (preg_match('/^12/', $relcode)) {
          // Identify the method with the IPPF code for the corresponding surgical procedure.
          if ($relcode == '121181999') $relcode = '121181213';
          if ($relcode == '122182999') $relcode = '122182213';
          $relcode = substr($relcode, 0, 7) . '13';
        }
        else {
          // Xavier confirms that the codes for Cervical Cap (112152010 and 112152011) are
          // an unintended change in pattern, but at this point we have to live with it.
          // -- Rod 2011-09-26
          $relcode = substr($relcode, 0, 6) . '110';
          if ($relcode == '112152110') $relcode = '112152010';
        }
        $line_contra_cyp      = $cyp;
        $line_contra_code     = $relcode;
        $line_contra_methtype = $methtype;
      }
    }
  }
}

// These variables are used to compute the initial consult service with highest CYP.
//
$contraception_code = '';
$contraception_cyp  = 0;
 ?>