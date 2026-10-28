/*
 * PROCTOR's front page. Small, vanilla, no build. Every word on the page is in
 * the HTML already; this file only moves the pencil.
 *
 * The proctor has read this file. Twice.
 */
(function () {
  "use strict";

  var root = document.documentElement;
  root.classList.add("js");
  var motion = root.classList.contains("motion");

  /* ---------------------------------------------------------------- toast */
  var toast = document.querySelector(".toast");
  var toastTimer = 0;
  function say(text) {
    if (!toast) return;
    toast.textContent = text;
    toast.classList.add("is-up");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toast.classList.remove("is-up");
      setTimeout(function () { toast.textContent = ""; }, 400);
    }, 2400);
  }

  /* ----------------------------------------------------------- copy buttons */
  var copyLines = ["Copied. The proctor allows it.", "Copied. That's not cheating, it's the docs.", "Copied. Eyes back on your own terminal."];
  var copyCount = 0;
  document.querySelectorAll("[data-copy]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var text = btn.getAttribute("data-copy");
      var label = btn.querySelector(".copy__label");
      function done() {
        if (label) label.textContent = "Copied";
        btn.classList.remove("is-copied");
        void btn.offsetWidth;
        btn.classList.add("is-copied");
        say(copyLines[copyCount++ % copyLines.length]);
        setTimeout(function () { if (label) label.textContent = "Copy"; }, 1800);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { say("Couldn't copy. Select the line by hand."); });
      } else {
        say("Couldn't copy. Select the line by hand.");
      }
    });
  });

  /* ------------------------------------------------------------------ tabs */
  document.querySelectorAll("[data-tabs]").forEach(function (group) {
    var tabs = Array.prototype.slice.call(group.querySelectorAll('[role="tab"]'));
    function select(tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute("aria-selected", on ? "true" : "false");
        t.tabIndex = on ? 0 : -1;
        var pane = document.getElementById(t.getAttribute("aria-controls"));
        if (pane) {
          pane.hidden = !on;
          if (on && motion) {
            pane.classList.remove("is-flipped");
            void pane.offsetWidth;
            pane.classList.add("is-flipped");
          }
        }
      });
      if (focus) tab.focus();
    }
    tabs.forEach(function (tab, i) {
      tab.addEventListener("click", function () { select(tab, false); });
      tab.addEventListener("keydown", function (e) {
        var next = null;
        if (e.key === "ArrowRight") next = tabs[(i + 1) % tabs.length];
        else if (e.key === "ArrowLeft") next = tabs[(i - 1 + tabs.length) % tabs.length];
        else if (e.key === "Home") next = tabs[0];
        else if (e.key === "End") next = tabs[tabs.length - 1];
        if (next) { e.preventDefault(); select(next, true); }
      });
    });
  });

  /* ------------------------------------------------------- the watchful eye */
  // Clicking the proctor is allowed. It will be noted.
  var brand = document.querySelector(".masthead__brand");
  if (brand) {
    brand.addEventListener("click", function () { say("Eyes on your own paper."); });
  }
  // Type "cheat" anywhere. Go on.
  var typed = "";
  document.addEventListener("keydown", function (e) {
    if (!e.key || e.key.length !== 1) return;
    typed = (typed + e.key.toLowerCase()).slice(-5);
    if (typed === "cheat") say("The proctor saw that.");
  });

  if (!motion) return;

  /* The exam book's seal turns its head toward the pointer. Only the tile
     moves; the mark itself is never altered. */
  var seal = document.querySelector(".book__seal");
  var book = document.querySelector(".book");
  if (seal && book && window.matchMedia("(pointer: fine)").matches) {
    var raf = 0;
    window.addEventListener("pointermove", function (e) {
      if (raf) return;
      raf = requestAnimationFrame(function () {
        raf = 0;
        var r = seal.getBoundingClientRect();
        var dx = (e.clientX - (r.left + r.width / 2)) / window.innerWidth;
        var dy = (e.clientY - (r.top + r.height / 2)) / window.innerHeight;
        seal.style.setProperty("--ry", (Math.max(-1, Math.min(1, dx * 2)) * 16).toFixed(2) + "deg");
        seal.style.setProperty("--rx", (Math.max(-1, Math.min(1, -dy * 2)) * 12).toFixed(2) + "deg");
      });
    }, { passive: true });
  }

  /* ------------------------------------------------------- scroll reveals */
  var reveals = document.querySelectorAll("[data-reveal]");
  if ("IntersectionObserver" in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add("is-in"); io.unobserve(en.target); }
      });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.08 });
    reveals.forEach(function (el, i) {
      el.style.transitionDelay = (i % 3) * 70 + "ms";
      io.observe(el);
    });
  } else {
    reveals.forEach(function (el) { el.classList.add("is-in"); });
  }

  /* ------------------------------------------- the sheet that marks itself */
  var sheet = document.querySelector("[data-sheet]");
  var steps = Array.prototype.slice.call(document.querySelectorAll(".step"));
  if (!sheet || !steps.length) return;

  sheet.setAttribute("data-live", "");
  var marks = Array.prototype.slice.call(sheet.querySelectorAll("[data-at]"));
  var ring = sheet.querySelector(".redpen-ring");
  var fail = sheet.querySelector(".result__fail");
  var current = -1;

  function setStep(n) {
    if (n === current) return;
    var forward = n > current;
    current = n;
    sheet.setAttribute("data-step", String(n));
    marks.forEach(function (m) {
      var at = Number(m.getAttribute("data-at"));
      var until = m.hasAttribute("data-until") ? Number(m.getAttribute("data-until")) : Infinity;
      m.classList.toggle("on", n >= at);
      m.classList.toggle("off", n >= until);
    });
    if (ring) ring.classList.toggle("on", n >= 3);
    if (fail) fail.classList.toggle("struck", n >= 5);
    steps.forEach(function (s) { s.classList.toggle("is-current", Number(s.getAttribute("data-step")) === n); });
    if (n === 5 && forward) {
      sheet.classList.remove("thunk");
      void sheet.offsetWidth;
      sheet.classList.add("thunk");
    }
  }

  function onScroll() {
    var vh = window.innerHeight;
    // On phones the sheet sits on top and steps arrive from below, so a step
    // counts as soon as it is well into view; on desktop, at mid-screen.
    var line = window.innerWidth < 960 ? vh * 0.82 : vh * 0.6;
    var n = 0;
    steps.forEach(function (s) {
      if (s.getBoundingClientRect().top < line) n = Number(s.getAttribute("data-step"));
    });
    setStep(n);
  }

  var ticking = false;
  window.addEventListener("scroll", function () {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () { ticking = false; onScroll(); });
  }, { passive: true });
  window.addEventListener("resize", onScroll);
  onScroll();
})();
