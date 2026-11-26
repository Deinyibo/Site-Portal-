  function initImageFallbacks() {
    document.querySelectorAll('img[data-fallback]').forEach(function (img) {
      function replace() {
        if (img.dataset.fbkDone) return;
        img.dataset.fbkDone = '1';
        var ph = document.createElement('div');
        ph.className = 'img-fallback';
        ph.textContent = 'IRIEBE TOWN SECONDARY SCHOOL';
        if (img.parentNode) img.parentNode.replaceChild(ph, img);
      }
      if (img.complete && img.naturalWidth === 0) replace();
      else img.addEventListener('error', replace);
    });
  }

  /* ---------------------------------------------------------
     12. CURRENT YEAR IN FOOTER
  --------------------------------------------------------- */
  function setYear() {
    var el = document.getElementById('year');
    if (el) el.textContent = new Date().getFullYear();
  }

  /* ---------------------------------------------------------
     BOOT
  --------------------------------------------------------- */
  document.addEventListener('DOMContentLoaded', function () {
    loadContentOverrides();
    initNav();
    initActiveNav();
    initHeroSlideshow();
    initReveal();
    initCounters();
    initTestimonials();
    initFAQ();
    initBackToTop();
    initContactForm();
    initImageFallbacks();
    setYear();
  });

})();
