<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
  
  $GLOBALS['GLOBALS_METADATA']['Menu']= array(

    'gbl_menu_stats_ippf' => array(
      xl('IPPF Statistics Reporting'),
      'bool',                           // data type
      '1',                              // default
      xl('IPPF statistical reports.')
    ),

    'gbl_menu_stats_gcac' => array(
      xl('GCAC Statistics Reporting'),
      'bool',                           // data type
      '1',                              // default
      xl('GCAC statistical reports.')
    ),

    'gbl_menu_stats_ma' => array(
      xl('MA Statistics Reporting'),
      'bool',                           // data type
      '1',                              // default
      xl('MA statistical reports.')
    ),

    'gbl_menu_stats_cyp' => array(
      xl('CYP Statistics Reporting'),
      'bool',                           // data type
      '1',                              // default
      xl('CYP statistical reports.')
    ),

    'gbl_menu_stats_daily' => array(
      xl('Daily Statistics Reporting'),
      'bool',                           // data type
      '1',                              // default
      xl('Daily statistical reports.')
    ),

    'gbl_menu_stats_c3' => array(
      xl('C3 Statistics Reporting'),
      'bool',                           // data type
      '1',                              // default
      xl('C3 statistical reports.')
    ),

    'gbl_menu_acct_trans' => array(
      xl('Accounting Transactions Export'),
      'bool',                           // data type
      '0',                              // default
      xl('Accounting transactions export to CSV')
    ),
      
    'gbl_menu_projects' => array(
      xl('Restricted Projects Reporting'),
      'bool', // data type
      '0', // default
      xl('For IPPF Belize and maybe others')
    ),
  );

?>