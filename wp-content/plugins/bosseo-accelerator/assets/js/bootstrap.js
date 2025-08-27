(function(){
  if (typeof window === 'undefined') return;
  var CFG = (window.BACC_BOOT || {});
  var DIAG_SUPPRESS = CFG.diag && CFG.diag.suppress;
  var HERO_MODE = CFG.hero_mode || 'smart';
  var delayedInfo = { restored: 0, hydrated: [] };

  function log(){ if (!DIAG_SUPPRESS && window.console && console.debug) console.debug.apply(console, arguments); }

  // INP guard: queue small tasks, hydrate gradually
  function TaskQueue(){
    var queue = [];
    var flushing = false;
    var budgetMs = 20; // per flush
    function flush(){
      if (flushing) return; flushing = true;
      var start = performance.now();
      while (queue.length){
        var task = queue.shift();
        try { task(); } catch(e){}
        if (performance.now() - start > budgetMs){ break; }
      }
      flushing = false;
      if (queue.length){ schedule(); }
    }
    function schedule(){
      if ('requestIdleCallback' in window){ requestIdleCallback(flush, {timeout: 250}); }
      else { setTimeout(flush, 0); }
    }
    return {
      push: function(fn){ queue.push(fn); schedule(); },
      size: function(){ return queue.length; }
    };
  }

  var queue = TaskQueue();
  var interactions = [];
  function trackInteraction(){
    var t0 = performance.now();
    return function(){
      var dt = performance.now() - t0;
      interactions.push(dt);
      if (!DIAG_SUPPRESS && interactions.length <= 3) console.debug('BACC interaction', interactions.length, 'duration(ms)=', Math.round(dt));
    };
  }

  function restoreDelayedScripts(){
    var nodes = document.querySelectorAll('script[type="bacc/defer"][data-bacc-src]');
    var count = 0;
    nodes.forEach(function(old){
      queue.push(function(){
        var s = document.createElement('script');
        // copy attributes except type and src
        for (var i=0;i<old.attributes.length;i++){
          var a = old.attributes[i];
          if (a.name === 'type' || a.name === 'src') continue;
          if (a.name.indexOf('data-bacc-') === 0) continue;
          s.setAttribute(a.name, a.value);
        }
        var src = old.getAttribute('data-bacc-src');
        if (src) s.src = src;
        old.parentNode.insertBefore(s, old.nextSibling);
        old.parentNode.removeChild(old);
        count++;
      });
    });
    delayedInfo.restored = nodes.length;
    log('BACC: restoring delayed scripts:', nodes.length);
  }

  function hydrateVideo(el){
    if (!el || el.__baccHydrated) return;
    el.__baccHydrated = true;
    // restore src on <video>
    var vs = el.getAttribute('data-bacc-src');
    if (vs) el.setAttribute('src', vs);
    // restore on <source>
    var sources = el.querySelectorAll('source[data-bacc-src]');
    sources.forEach(function(source){
      var s = source.getAttribute('data-bacc-src');
      if (s) source.setAttribute('src', s);
      source.removeAttribute('data-bacc-src');
    });
    // autoplay muted after hydrate
    if (el.getAttribute('data-bacc-autoplay') === '1'){
      el.muted = true; // ensure muted for autoplay policy
      el.autoplay = true;
      var p = el.play(); if (p && p.catch) p.catch(function(){});
    } else if (el.getAttribute('data-bacc-muted') === '1') {
      el.muted = true;
    }
    delayedInfo.hydrated.push(el.getAttribute('data-bacc-target') || 'unknown');
    log('BACC: hydrated video', el);
  }

  function setupVideoObserver(){
    var all = Array.prototype.slice.call(document.querySelectorAll('video[data-bacc-lazy="1"]'));
    if (!all.length) return;
    var mobile = window.matchMedia('(max-width: 767px)');
    var observer = new IntersectionObserver(function(entries){
      entries.forEach(function(ent){
        if (!ent.isIntersecting) return;
        var v = ent.target;
        if (HERO_MODE === 'smart'){
          var target = v.getAttribute('data-bacc-target');
          if (target === 'mobile' && !mobile.matches) return;
          if (target === 'desktop' && mobile.matches) return;
          var style = window.getComputedStyle(v);
          if (style && style.display === 'none' || style.visibility === 'hidden') return;
        }
        queue.push(function(){ hydrateVideo(v); });
        observer.unobserve(v);
      });
    }, { rootMargin: '0px 0px 200px 0px', threshold: 0.1 });
    all.forEach(function(v){ observer.observe(v); });
  }

  function installTriggers(){
    var fired = false;
    function trigger(reason){
      if (fired) return; fired = true;
      var done = trackInteraction();
      restoreDelayedScripts();
      done();
    }
    ['pointerdown','keydown','touchstart'].forEach(function(ev){
      window.addEventListener(ev, function(){ trigger(ev); }, { once: true, passive: true });
    });
    if ('requestIdleCallback' in window){ requestIdleCallback(function(){ trigger('idle'); }, { timeout: 2000 }); }
    else { window.addEventListener('load', function(){ setTimeout(function(){ trigger('load'); }, 1000); }); }
  }

  function diagnostics(){
    try {
      var diag = document.getElementById('bacc-diag');
      if (!diag) return;
      diag.setAttribute('data-restored-scripts', String(delayedInfo.restored || 0));
      diag.setAttribute('data-hydrated-videos', delayedInfo.hydrated.join(','));
    } catch(e){}
  }

  function lcpPosterPreload(){
    // Already injected server-side for poster and first img.
  }

  function init(){
    if (CFG.delay_third_party) installTriggers();
    setupVideoObserver();
    window.addEventListener('load', function(){ setTimeout(diagnostics, 0); }, { once: true });
  }

  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

