(function($){
    function reindexRows($table){
      $table.find('tbody .frm-gmail-row').each(function(i){
        $(this).find('input, textarea, select').each(function(){
          var name = $(this).attr('name') || '';
          // replace [<num>] in frm_gmail[accounts][<num>][key]
          name = name.replace(/frm_gmail\[accounts]\[(\d+)]\[(.*?)\]/, 'frm_gmail[accounts]['+i+'][$2]');
          // Also handle template placeholders if any slipped in
          name = name.replace(/__name__\[(.*?)\]/, 'frm_gmail[accounts]['+i+'][$1]');
          $(this).attr('name', name);
        });
      });
    }
  
    $(document).on('click', '#frm-gmail-add', function(){
      var $table = $('#frm-gmail-accounts');
      var $tbody = $table.find('tbody');
      var $tpl   = $('#frm-gmail-row-template').html();
      // set placeholders first, reindex afterwards
      var $row = $($tpl.replaceAll('__name__', 'frm_gmail[accounts][999]'));
      $tbody.append($row);
      reindexRows($table);
    });
  
    $(document).on('click', '.frm-gmail-remove', function(){
      var $table = $('#frm-gmail-accounts');
      $(this).closest('tr').remove();
      reindexRows($table);
    });
  })(jQuery);
