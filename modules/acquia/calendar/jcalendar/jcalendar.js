if (Drupal.jsEnabled) {
  $(document).ready(function() {
    // I am assuming that all of the links are refering to an internal node
    
    // add the attribute rel=facebox to all of the links I want to have a popup
    $('div.view-field > a').attr('class', 'popup');
   
    // on click of a link
    $('a.popup').click(function(a) {
    
      // Make sure that other stuff is closed. This seems to cause a problem in Fx2 and IE7.
      $('div#calpopup').fadeOut();
      $('div#calpopup').remove();
      
      // create div to hold data and add it to the end of the body
      var div = $('<div id="calpopup"><div id="popup-close"><img id="popup-close-img" src="' + var_path + '/images/cross.png" /></div><div id="calpopup-body"><img src="' + var_path + '/images/throbber.gif" id="popthrobber" /></div></div>').attr('style','display: none');
      div.appendTo(document.body);
      
      // Locate Popup
      var offset = $(this).offset();
      // Check position with window width.
      var offset_left = offset.left;
      if ($(window).width() < $('#calpopup').width() + offset.left) {
        offset_left = $(window).width() - $('#calpopup').width() - 5;
      }
      $('#calpopup').css('left', offset_left);
      $('#calpopup').css('top', offset.top + 25);
      
      // Show Popup
      $('#calpopup').fadeIn('slow');
      
      // If sucessful call this
      function domCallback(msg) {
        $('#calpopup-body').html(msg);
      }
      
      // Get NodeID and ItemID
      var ids = $(this).parent().parent().attr('id');
      var arr = ids.split(":");
      var nid = arr[1];
      var id = arr[4];
            
      // fill the div with data
      $.ajax({
        type: "GET",
        url: var_base_path + "jcalendar/getnode/"+nid+"/"+ids,
        success: function(msg){
          domCallback(msg);
        }
      });
      
      // On click of the close image
      $('img#popup-close-img').click(function(x) {
        $('div#calpopup').fadeOut();
        $('div#calpopup').remove();
      });
      
      $(document).click(function(y) {
        var $tgt = $(y.target);
        if (!$tgt.parents().is('div#calpopup')) {
          $('div#calpopup').fadeOut();
          $('div#calpopup').remove();
          $(document).unbind("click");
        }
      });
      
      // Don't Follow the real link
      return false;
    });
  });
}