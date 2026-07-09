(function(){
  'use strict';

  var wrap=document.querySelector('[data-profile-menu]');
  if(!wrap)return;

  var trigger=wrap.querySelector('[data-profile-trigger]');
  var menu=wrap.querySelector('[data-profile-dropdown]');
  var logout=wrap.querySelector('.profile-dropdown-logout');
  if(!trigger||!menu)return;

  function setOpen(open){
    trigger.setAttribute('aria-expanded',open?'true':'false');
    menu.hidden=!open;
  }

  trigger.addEventListener('click',function(ev){
    ev.preventDefault();
    ev.stopPropagation();
    setOpen(trigger.getAttribute('aria-expanded')!=='true');
  });

  menu.addEventListener('click',function(ev){
    ev.stopPropagation();
  });

  if(logout){
    logout.addEventListener('click',function(ev){
      ev.preventDefault();
      ev.stopPropagation();

      logout.classList.add('is-logging-out');
      logout.setAttribute('aria-disabled','true');
      var label=logout.querySelector('b');
      if(label)label.textContent='Logging out...';

      try{ window.sessionStorage.clear(); }catch(ignore){}

      // Absolute path avoids problems when the dashboard is opened using a nested URL.
      window.location.assign('/zurie/logout.php?t='+Date.now());
    });
  }

  document.addEventListener('click',function(){setOpen(false);});
  document.addEventListener('keydown',function(ev){
    if(ev.key==='Escape'){
      setOpen(false);
      trigger.focus();
    }
  });
})();
