(function () {
  "use strict";

  var phases = [
    "queued",
    "fetching GitHub repository files",
    "preparing content",
    "URL treatment",
    "importing media",
    "writing pages",
    "complete",
  ];

  var featureDetails = {
    detect: "Identifies the source type and expected importer behavior before anything is written.",
    map: "Previews how source fields become WordPress records and metadata.",
    conflicts: "Shows duplicate, ownership, and blocked-record decisions before the run continues.",
    overview: "Shows the full import path from queue to published pages.",
    urls: "Choose how imported URLs are rewritten before content is created.",
    media: "Tracks image and file imports separately from page writes.",
    review: "Surfaces decisions that need confirmation before completion.",
    timeline: "Records each importer event as the prototype advances.",
  };

  var sourceScenarioFallbacks = {
    "wordpress-newsroom": {
      label: "Newsroom WXR",
      value: "/srv/imports/newsroom-export.xml",
      helper: "A WordPress export source can include posts, pages, media, comments, terms, menus, authors, and legacy URLs.",
      summary: "Newsroom WXR selected. The review will inspect WordPress export records, media references, authors, terms, and rollback details.",
    },
    "commerce-csv": {
      label: "Products CSV",
      value: "/srv/imports/products.csv",
      helper: "A CSV source is inspected for headers, repeated values, media URLs, and fields that need mapping before import.",
      summary: "Products CSV selected. The review will focus on field mapping, conflict handling, media URLs, and skipped rows.",
    },
    "docs-markdown": {
      label: "Docs archive",
      value: "/srv/imports/docs-archive.zip",
      helper: "A documentation archive is expanded before Markdown and HTML files are prepared as WordPress pages.",
      summary: "Docs archive selected. The review will show discovered documents, page hierarchy, media references, and URL treatment.",
    },
  };

  var state = {
    activeFeature: "overview",
    aborted: false,
    completed: false,
    phaseIndex: -1,
    timer: null,
    urlDecision: "preserve",
    counts: {
      files: 0,
      media: 0,
      pages: 0,
      reviewed: 0,
    },
  };

  var ui = {};

  function ready(callback) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback, { once: true });
    } else {
      callback();
    }
  }

  function normalizeText(value) {
    return String(value || "").trim().toLowerCase();
  }

  function bySelector(selectors) {
    for (var i = 0; i < selectors.length; i += 1) {
      var element = document.querySelector(selectors[i]);
      if (element) {
        return element;
      }
    }
    return null;
  }

  function allBySelector(selectors) {
    var seen = [];
    selectors.forEach(function (selector) {
      Array.prototype.forEach.call(document.querySelectorAll(selector), function (element) {
        if (seen.indexOf(element) === -1) {
          seen.push(element);
        }
      });
    });
    return seen;
  }

  function findButtons(names, actions) {
    var matches = [];
    var actionSet = actions.map(normalizeText);
    var nameSet = names.map(normalizeText);

    Array.prototype.forEach.call(document.querySelectorAll("button, [role='button'], a, input[type='button'], input[type='submit']"), function (element) {
      var action = normalizeText(element.getAttribute("data-action"));
      var text = normalizeText(element.value || element.textContent || element.getAttribute("aria-label"));
      var isMatch = actionSet.indexOf(action) !== -1 || nameSet.some(function (name) {
        return text === name || text.indexOf(name) !== -1;
      });

      if (isMatch && matches.indexOf(element) === -1) {
        matches.push(element);
      }
    });

    return matches;
  }

  function ensurePanel() {
    var host = document.querySelector("main") || document.body;
    var panel = document.createElement("section");
    panel.className = "importer-live-panel";
    panel.setAttribute("aria-live", "polite");
    panel.innerHTML = [
      "<div class='importer-live-header'>",
      "<strong>Import progress</strong>",
      "<span data-importer-status>Idle</span>",
      "</div>",
      "<div class='importer-live-track'><span data-importer-bar></span></div>",
      "<div class='importer-live-counts'>",
      "<span data-count='files'>0 files</span>",
      "<span data-count='media'>0 media</span>",
      "<span data-count='pages'>0 pages</span>",
      "</div>",
      "<div class='importer-live-actions'>",
      "<button type='button' data-action='start-import'>Start import</button>",
      "<button type='button' data-action='abort'>Abort</button>",
      "<button type='button' data-action='retry'>Retry</button>",
      "<button type='button' data-action='review'>Review</button>",
      "</div>",
      "<div class='importer-live-detail' data-feature-detail>Shows importer interaction state.</div>",
      "<ol class='importer-live-log' data-importer-log></ol>",
    ].join("");

    if (!document.querySelector(".importer-live-panel")) {
      host.appendChild(panel);
    }
  }

  function discoverUi() {
    ui.status = bySelector([
      "[data-importer-status]",
      "[data-status]",
      ".import-status",
      ".status-text",
      ".progress-status",
      "#import-status",
    ]);
    ui.bars = allBySelector([
      "[data-importer-bar]",
      "[data-progress-bar]",
      "progress",
      ".progress-bar",
      "[role='progressbar']",
    ]);
    ui.stageCards = allBySelector([
      "[data-stage]",
      "[data-phase]",
      ".stage-card",
      ".phase-card",
      ".timeline-step",
    ]);
    ui.logs = allBySelector([
      "[data-importer-log]",
      "[data-log]",
      "[data-timeline]",
      ".import-log",
      ".timeline",
      ".activity-log",
    ]);
    ui.counts = allBySelector([
      "[data-count]",
      "[data-import-count]",
      ".import-count",
      ".count",
    ]);
    ui.detail = bySelector([
      "[data-feature-detail]",
      ".feature-detail",
      ".tab-detail",
      ".selection-detail",
    ]);
    ui.featureTabs = allBySelector([
      "[data-feature-tab]",
    ]);
    ui.featurePanels = allBySelector([
      "[data-feature-panel]",
    ]);
    ui.sourceInput = bySelector([
      "[data-source-input]",
      "#source-input",
      ".importer-source-input",
      "input[name='source']",
    ]);
    ui.sourceHelpers = allBySelector([
      "[data-source-helper]",
      "#source-help",
      ".source-helper",
      ".source-help",
    ]);
    ui.sourceSummaries = allBySelector([
      "[data-source-summary]",
      "#source-summary",
      ".source-summary",
      ".setup-summary",
    ]);
    ui.reviewPanels = allBySelector([
      "[data-results-panel]",
      "[data-review-panel]",
      "#review-result",
      ".review-result",
      ".results-panel",
    ]);
    ui.reviewState = bySelector([
      "[data-result-state]",
      "#review-result-state",
      ".review-result-state",
      ".result-state",
    ]);
    ui.reviewSummary = bySelector([
      "[data-result-summary]",
      "#review-result-summary",
      ".review-result-summary",
      ".result-summary",
    ]);
    ui.reviewActions = allBySelector([
      "#review-result-actions button",
      "[data-result-action]",
      "[data-action='run-import']",
      "[data-action='download-report']",
    ]);

    if (!ui.status && !ui.bars.length && !ui.logs.length) {
      ensurePanel();
      discoverUi();
    }
  }

  function setActive(elements, activeElement) {
    elements.forEach(function (element) {
      var active = element === activeElement;
      element.classList.toggle("is-active", active);
      element.classList.toggle("active", active);
      if (element.matches("button, [role='tab'], [role='button']")) {
        element.setAttribute("aria-selected", active ? "true" : "false");
      }
    });
  }

  function setTextContent(element, text, useParagraph) {
    if (!element || !text) return;
    while (element.firstChild) {
      element.removeChild(element.firstChild);
    }
    if (useParagraph) {
      var paragraph = document.createElement("p");
      paragraph.textContent = text;
      element.appendChild(paragraph);
    } else {
      element.textContent = text;
    }
  }

  function isVisible(element) {
    return element && !element.hidden && element.getAttribute("aria-hidden") !== "true";
  }

  function dispatchInputChange(element) {
    if (!element) return;
    element.dispatchEvent(new Event("input", { bubbles: true }));
    element.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function findById(items, id) {
    var result = null;
    if (!items || !id) return result;

    items.some(function (item) {
      if (item && item.id === id) {
        result = item;
        return true;
      }
      return false;
    });

    return result;
  }

  function getScenarioInfo(id, button) {
    var data = window.ImporterPrototypeData || {};
    var scenario = findById(data.scenarios, id);
    var sourceExample = findById(data.sourceExamples, id);
    var fallback = sourceScenarioFallbacks[id] || {};
    var value = button.getAttribute("data-value") || button.getAttribute("data-source");

    if (!sourceExample && scenario && scenario.sourceExampleId) {
      sourceExample = findById(data.sourceExamples, scenario.sourceExampleId);
    }

    return {
      id: id,
      label: button.textContent.trim() || fallback.label || (sourceExample && sourceExample.label) || (scenario && scenario.label) || id,
      value: value || fallback.value || (sourceExample && sourceExample.value) || id,
      helper: button.getAttribute("data-helper") || fallback.helper || (sourceExample && sourceExample.helper) || (scenario && scenario.goal),
      summary: button.getAttribute("data-summary") || fallback.summary || (scenario && scenario.resultCopy && scenario.resultCopy.summary) || (sourceExample && sourceExample.expectedResult),
    };
  }

  function syncFeaturePanels(id) {
    var matchingPanel = null;

    if (!id || !ui.featurePanels || !ui.featurePanels.length) {
      return false;
    }

    ui.featurePanels.some(function (panel) {
      if (panel.getAttribute("data-feature-panel") === id) {
        matchingPanel = panel;
        return true;
      }
      return false;
    });

    if (!matchingPanel) {
      return false;
    }

    ui.featureTabs.forEach(function (tab) {
      var active = tab.getAttribute("data-feature-tab") === id;
      tab.classList.toggle("is-active", active);
      tab.classList.toggle("active", active);
      tab.setAttribute("aria-selected", active ? "true" : "false");
      if (tab.getAttribute("role") === "tab") {
        tab.tabIndex = active ? 0 : -1;
      }
    });

    ui.featurePanels.forEach(function (panel) {
      var active = panel === matchingPanel;
      panel.hidden = !active;
      panel.classList.toggle("is-active", active);
      panel.classList.toggle("active", active);
      panel.setAttribute("aria-hidden", active ? "false" : "true");
    });

    return true;
  }

  function addLog(message) {
    var stamp = new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit" });

    ui.logs.forEach(function (log) {
      var item = document.createElement(log.tagName === "UL" || log.tagName === "OL" ? "li" : "div");
      item.textContent = stamp + " - " + message;
      log.appendChild(item);
      log.scrollTop = log.scrollHeight;
    });
  }

  function updateBars(progress) {
    ui.bars.forEach(function (bar) {
      if (bar.tagName === "PROGRESS") {
        bar.max = 100;
        bar.value = progress;
      } else {
        bar.style.width = progress + "%";
        bar.setAttribute("aria-valuemin", "0");
        bar.setAttribute("aria-valuemax", "100");
        bar.setAttribute("aria-valuenow", String(progress));
      }
    });
  }

  function updateCounts() {
    ui.counts.forEach(function (element) {
      var key = element.getAttribute("data-count") || element.getAttribute("data-import-count");
      var text = normalizeText(element.textContent);

      if (!key) {
        if (text.indexOf("media") !== -1) key = "media";
        else if (text.indexOf("page") !== -1) key = "pages";
        else if (text.indexOf("review") !== -1) key = "reviewed";
        else key = "files";
      }

      if (Object.prototype.hasOwnProperty.call(state.counts, key)) {
        element.textContent = state.counts[key] + " " + key;
      }
    });
  }

  function updateStages(phase) {
    ui.stageCards.forEach(function (card) {
      var stage = normalizeText(card.getAttribute("data-stage") || card.getAttribute("data-phase") || card.textContent);
      var active = stage.indexOf(normalizeText(phase)) !== -1;
      var done = phases.indexOf(stage) !== -1 && phases.indexOf(stage) < state.phaseIndex;

      card.classList.toggle("is-active", active);
      card.classList.toggle("active", active);
      card.classList.toggle("is-complete", done);
      card.classList.toggle("complete", done);
    });
  }

  function updateStatus(phase) {
    var progress = state.phaseIndex < 0 ? 0 : Math.round((state.phaseIndex / (phases.length - 1)) * 100);

    if (ui.status) {
      ui.status.textContent = state.aborted ? "Aborted" : phase;
    }

    updateBars(progress);
    updateStages(phase);
    updateCounts();
  }

  function advanceCounts(phase) {
    if (phase === "fetching GitHub repository files") state.counts.files = 18;
    if (phase === "preparing content") state.counts.files = 32;
    if (phase === "importing media") state.counts.media = 14;
    if (phase === "writing pages") state.counts.pages = 9;
    if (phase === "complete") {
      state.counts.files = 32;
      state.counts.media = 18;
      state.counts.pages = 12;
      state.completed = true;
    }
  }

  function tick() {
    if (state.aborted) {
      window.clearInterval(state.timer);
      state.timer = null;
      updateStatus("Aborted");
      addLog("Import aborted");
      return;
    }

    state.phaseIndex += 1;
    var phase = phases[state.phaseIndex] || "complete";
    advanceCounts(phase);
    updateStatus(phase);
    addLog(phase);

    if (phase === "complete") {
      if (ui.reviewState) {
        ui.reviewState.textContent = "Complete";
      }
      if (ui.reviewSummary) {
        ui.reviewSummary.textContent = state.counts.pages + " pages, " + state.counts.media + " media, 0 warnings.";
      }
      window.clearInterval(state.timer);
      state.timer = null;
    }
  }

  function startImport() {
    if (state.timer) return;
    reset(false);
    tick();
    state.timer = window.setInterval(tick, 800);
  }

  function abort() {
    state.aborted = true;
    if (!state.timer) {
      updateStatus("Aborted");
      addLog("Import aborted");
    }
  }

  function reset(writeLog) {
    if (state.timer) window.clearInterval(state.timer);
    state.timer = null;
    state.aborted = false;
    state.completed = false;
    state.phaseIndex = -1;
    state.counts = { files: 0, media: 0, pages: 0, reviewed: 0 };
    updateStatus("Idle");
    if (writeLog !== false) addLog("Import reset");
  }

  function chooseFeature(id) {
    state.activeFeature = id || "overview";
    if (ui.detail) {
      ui.detail.textContent = featureDetails[state.activeFeature] || "Previewing " + state.activeFeature + " importer behavior.";
    }
    addLog("Selected " + state.activeFeature);
  }

  function chooseUrlDecision(value) {
    state.urlDecision = value || "preserve";
    addLog("URL decision: " + state.urlDecision);
    chooseFeature("urls");
  }

  function review() {
    state.counts.reviewed += 1;
    updateCounts();
    chooseFeature("review");
    addLog("Review opened");
  }

  function attachActions() {
    findButtons(["start import", "import"], ["start-import", "start", "import"]).forEach(function (button) {
      button.addEventListener("click", function (event) {
        event.preventDefault();
        startImport();
      });
    });
    findButtons(["abort", "cancel"], ["abort", "cancel"]).forEach(function (button) {
      button.addEventListener("click", function (event) {
        event.preventDefault();
        abort();
      });
    });
    findButtons(["retry", "reset"], ["retry", "reset"]).forEach(function (button) {
      button.addEventListener("click", function (event) {
        event.preventDefault();
        reset();
        startImport();
      });
    });
    findButtons(["review"], ["review"]).forEach(function (button) {
      button.addEventListener("click", function (event) {
        event.preventDefault();
        review();
      });
    });
    findButtons(["preserve urls", "rewrite urls", "skip urls"], ["preserve-urls", "rewrite-urls", "skip-urls", "url-decision"]).forEach(function (button) {
      button.addEventListener("click", function (event) {
        event.preventDefault();
        chooseUrlDecision(button.getAttribute("data-value") || button.getAttribute("data-decision") || normalizeText(button.textContent));
      });
    });
  }

  function attachDiscovery() {
    var controls = allBySelector([
      "[data-feature]",
      "[data-feature-tab]",
      "[data-tab]",
      "[data-chip]",
      "[role='tab']",
      ".tab",
      ".chip",
      ".feature-button",
    ]);

    controls.forEach(function (control) {
      control.addEventListener("click", function () {
        var featureTab = control.getAttribute("data-feature-tab");
        var id = featureTab || control.getAttribute("data-feature") || control.getAttribute("data-tab") || control.getAttribute("data-chip") || normalizeText(control.textContent).replace(/\s+/g, "-");
        if (!featureTab || !syncFeaturePanels(featureTab)) {
          setActive(controls, control);
        }
        chooseFeature(id);
      });
    });
  }

  window.ImporterPrototype = {
    startImport: startImport,
    abort: abort,
    reset: reset,
    chooseFeature: chooseFeature,
  };

  ready(function () {
    discoverUi();
    attachActions();
    attachDiscovery();
    updateStatus("Idle");
    chooseFeature("overview");
  });
}());
