(function(){
  'use strict';

  // Security/menu patch 20260624:
  // Jangan lagi ubah link /zurie/pages/*.php kepada fail root /zurie/*.php.
  // Fail root export telah dipadam/dinyahaktifkan untuk elak bypass login.
  var map={
    '/zurie/ilmu_export.php':'/zurie/pages/ilmu_export.php',
    '/zurie/isims_extract.php':'/zurie/pages/isims_extract.php',
    '/zurie/isims_senarai.php':'/zurie/pages/isims_senarai.php',
    '/zurie/ms365_export.php':'/zurie/pages/ms365_export.php'
  };

  function fix(){
    document.querySelectorAll('a[href]').forEach(function(a){
      try{
        var u=new URL(a.getAttribute('href'),window.location.href);
        if(map[u.pathname]){
          a.setAttribute('href',map[u.pathname]+(u.search||''));
          a.setAttribute('target','_self');
        }
      }catch(e){}
    });
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',fix); else fix();
})();
