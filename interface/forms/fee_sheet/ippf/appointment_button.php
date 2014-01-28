<script type="text/javascript" src="../../../library/dialog.js"></script>
<script>
    // Open the add-event dialog.
    function newEvt() {
     var f = document.forms[0];
     var url = '../../main/calendar/add_edit_event.php?patientid=<?php echo $pid ?>';
     if (f.ProviderID && f.ProviderID.value) {
      url += '&userid=' + parseInt(f.ProviderID.value);
     }
     dlgopen(url, '_blank', 800, 350);
     return false;
    }
</script>
<?php function add_appointment_button()
{ ?>
    <input type='button' value='<?php echo xla('New Appointment') ?>' onclick='newEvt()' />
<?php } ?>