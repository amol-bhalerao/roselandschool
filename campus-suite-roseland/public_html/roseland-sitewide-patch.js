(function () {
  const VERSION = "20260629-6";
  if (window.__roselandSitewidePatchVersion === VERSION) return;
  window.__roselandSitewidePatchVersion = VERSION;

  const MAIN_TABS = ["Course", "Subjects", "Personal", "Address", "Reservation", "Qualification", "Documents", "Declaration"];
  const NAME_LABELS = [
    /^Last name$/i,
    /^First name$/i,
    /^Middle name$/i,
    /^Father first name$/i,
    /^Mother first name$/i,
    /^Surname$/i,
    /^Father's first name$/i,
    /^Mother's first name$/i,
    /^Middle \/ Father name$/i,
    /^Father \/ Husband name$/i,
    /^Last name \/ Surname$/i,
  ];
  const HIDE_LABELS = [
    /^Name on marksheet/i,
    /^Name on marksheet \/ lc/i,
    /^Name as per Aadhaar/i,
    /^Name changed after last exam\?/i,
    /^Blood group/i,
    /^Married\?/i,
  ];

  const LETTER_MAP = new Map([
    ["a", "अ"], ["b", "ब"], ["c", "क"], ["d", "द"], ["e", "ए"], ["f", "फ"], ["g", "ग"],
    ["h", "ह"], ["i", "इ"], ["j", "ज"], ["k", "क"], ["l", "ल"], ["m", "म"], ["n", "न"],
    ["o", "ओ"], ["p", "प"], ["q", "क"], ["r", "र"], ["s", "स"], ["t", "त"], ["u", "उ"],
    ["v", "व"], ["w", "व"], ["x", "क्स"], ["y", "य"], ["z", "ज"],
  ]);

  function clean(value) {
    return String(value || "").replace(/\s+/g, " ").trim();
  }

  function visible(node) {
    if (!node || !(node instanceof Element)) return false;
    const style = getComputedStyle(node);
    return style.display !== "none" && style.visibility !== "hidden" && style.opacity !== "0";
  }

  function extraInputBase() {
    return "mt-1 w-full rounded-xl bg-white px-3 py-2.5 uppercase border-2 border-slate-300 shadow-sm outline-none transition focus:border-sky-600 focus:ring-4 focus:ring-sky-100";
  }

  function transliterateToMarathi(value) {
    const text = clean(value).toLowerCase();
    if (!text) return "";
    let result = "";
    for (const ch of text) {
      result += LETTER_MAP.get(ch) || ch;
    }
    return result.replace(/\s+/g, " ").trim();
  }

  function buildCompositeStudentName() {
    const values = Array.from(document.querySelectorAll("label")).reduce((acc, label) => {
      const control = label.querySelector("input, textarea");
      if (!control) return acc;
      const key = findLabelText(label).toLowerCase();
      acc[key] = clean(control.value);
      return acc;
    }, {});
    const first = values["first name"] || values["first name *"] || "";
    const middle = values["middle name"] || values["middle / father name"] || values["father first name"] || "";
    const last = values["last name"] || values["last name / surname"] || values["surname"] || "";
    return [first, middle, last].filter(Boolean).join(" ").replace(/\s+/g, " ").trim();
  }

  function isAdmissionPage() {
    return /\/admission(?:-form)?(?:\/|$)|\/erp\/admissions|\/erp\/enquiries/i.test(location.pathname);
  }

  function isPublicAdmissionPage() {
    return /\/admission(?:-form)?(?:\/|$)|\/public-admissions/i.test(location.pathname);
  }

  function ensureStyle() {
    if (document.getElementById("roseland-sitewide-patch-style")) return;
    const style = document.createElement("style");
    style.id = "roseland-sitewide-patch-style";
    style.textContent = `
      .roseland-hidden-field { display: none !important; }
      .roseland-marathi-block { margin-top: .75rem; }
      .roseland-marathi-block label { display: block; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: #64748b; }
      .roseland-dialog-top { position: relative !important; z-index: 80 !important; }
      [data-roseland-patch-hidden] { display: none !important; }
    `;
    document.head.appendChild(style);
  }

  function activeMainTab() {
    const buttons = Array.from(document.querySelectorAll("button"));
    const tab = buttons.find((button) => {
      const text = clean(button.textContent).replace(/\s+\d+$/, "");
      return MAIN_TABS.includes(text) && (
        button.getAttribute("aria-selected") === "true" ||
        /\bbg-sky|\bborder-sky|\btext-sky|\bfrom-sky/i.test(String(button.className || ""))
      );
    });
    return tab ? clean(tab.textContent).replace(/\s+\d+$/, "") : "";
  }

  function isRequiredField(control) {
    return !!control && !control.disabled && control.required && visible(control);
  }

  function controlFilled(control) {
    if (!control || control.disabled) return true;
    if (control.type === "checkbox") return control.checked;
    if (control.tagName === "SELECT") return clean(control.value) !== "";
    if (control.type === "file") return !!(control.files && control.files.length);
    return clean(control.value) !== "";
  }

  function visibleRequiredFilled() {
    return Array.from(document.querySelectorAll("input, select, textarea"))
      .filter(isRequiredField)
      .every(controlFilled);
  }

  function findLabelText(label) {
    return clean(label?.getAttribute("data-roseland-original-label") || label?.textContent || "");
  }

  function hideUnwantedFields() {
    Array.from(document.querySelectorAll("label, div, section")).forEach((node) => {
      if (!visible(node)) return;
      const label = node.matches("label") ? node : node.querySelector?.("label");
      const text = clean(label ? findLabelText(label) : node.textContent);
      if (!text) return;
      if (HIDE_LABELS.some((pattern) => pattern.test(text))) {
        node.setAttribute("data-roseland-patch-hidden", "true");
      }
    });
  }

  function insertMarathiPairs() {
    Array.from(document.querySelectorAll("label")).forEach((label) => {
      const englishControl = label.querySelector("input, textarea");
      if (!englishControl || !visible(englishControl)) return;
      const labelText = findLabelText(label);
      if (!NAME_LABELS.some((pattern) => pattern.test(labelText))) return;
      if (label.nextElementSibling && label.nextElementSibling.dataset?.roselandMarathiFor === labelText) return;

      const wrapper = document.createElement("label");
      wrapper.className = "roseland-marathi-block";
      wrapper.dataset.roselandMarathiFor = labelText;
      const marathiName = `${labelText} (Marathi)`;
      wrapper.innerHTML = `<span>${marathiName}</span><input type="text" class="${extraInputBase()}" data-roseland-marathi-for="${labelText}" autocomplete="off">`;
      const marathiInput = wrapper.querySelector("input");
      const storageKey = `roselandSitewide:${labelText}:mr`;
      marathiInput.value = sessionStorage.getItem(storageKey) || "";
      marathiInput.addEventListener("input", () => {
        marathiInput.dataset.roselandManual = "true";
        sessionStorage.setItem(storageKey, marathiInput.value);
      });
      englishControl.addEventListener("blur", () => {
        if (marathiInput.dataset.roselandManual === "true") return;
        const translated = transliterateToMarathi(englishControl.value);
        if (!translated) return;
        marathiInput.value = translated;
        sessionStorage.setItem(storageKey, translated);
      });
      englishControl.addEventListener("change", () => {
        if (marathiInput.dataset.roselandManual === "true") return;
        const translated = transliterateToMarathi(englishControl.value);
        if (!translated) return;
        marathiInput.value = translated;
        sessionStorage.setItem(storageKey, translated);
      });
      label.insertAdjacentElement("afterend", wrapper);
    });
  }

  function replaceSemesterText() {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const replacements = [
      [/\bSelected subjects by semester\b/gi, "Selected subjects for the year"],
      [/\bSemester\s*\d+\b/gi, "Annual"],
      [/\bSemester\b/gi, "Annual"],
    ];
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    for (const node of nodes) {
      const parent = node.parentElement;
      if (!parent || parent.closest("script, style, textarea, input, select, button, option, [data-roseland-patch-hidden]")) {
        continue;
      }
      let next = node.nodeValue;
      let changed = false;
      for (const [pattern, replacement] of replacements) {
        const updated = next.replace(pattern, replacement);
        if (updated !== next) {
          next = updated;
          changed = true;
        }
      }
      if (changed) node.nodeValue = next;
    }
  }

  function removeInstructionCopy() {
    const patterns = [
      /Complete the required fields on this tab to enable Next/i,
      /Select the course and class first\. Subject options will appear in the next tab based on this class setup\./i,
      /Please complete Course tab before opening Personal\./i,
      /Please update these details:/i,
      /Details saved locally\. Continue with the next section\./i,
    ];
    Array.from(document.querySelectorAll("p, span, div, li")).forEach((node) => {
      if (node.closest("label, button, select, option, input, textarea, [data-roseland-patch-hidden]")) return;
      const text = clean(node.textContent);
      if (!text || text.length > 180) return;
      if (patterns.some((pattern) => pattern.test(text))) node.setAttribute("data-roseland-patch-hidden", "true");
    });
    Array.from(document.querySelectorAll("h1, h2, h3, p")).forEach((node) => {
      const text = clean(node.textContent);
      if (/^Additional personal details$/i.test(text) || /PEN is kept here\. Marathi names appear next to the matching English name fields\./i.test(text)) {
        node.setAttribute("data-roseland-patch-hidden", "true");
      }
    });
  }

  function normalizeAdmissionHeading() {
    Array.from(document.querySelectorAll("h2")).forEach((node) => {
      const text = clean(node.textContent);
      if (text === "Course") {
        node.textContent = "Admission form";
      }
    });
  }

  function ensureMediumOptions() {
    Array.from(document.querySelectorAll("select")).forEach((select) => {
      const field = clean(select.getAttribute("data-field"));
      const label = select.closest("label");
      const labelText = clean(label ? findLabelText(label) : "");
      if (field !== "Medium" && !/^Medium$/i.test(labelText)) return;
      const desired = ["English", "Marathi", "Semi-English", "Arts", "Science", "Commerce"];
      const existing = new Set(Array.from(select.options).map((option) => clean(option.textContent)));
      desired.forEach((optionText) => {
        if (existing.has(optionText)) return;
        const option = document.createElement("option");
        option.textContent = optionText;
        option.value = optionText;
        select.appendChild(option);
      });
    });
  }

  function syncPersonalNext() {
    const form = document.querySelector("form");
    if (!form) return;
    const nextButton = Array.from(form.querySelectorAll("button")).find((button) => /next/i.test(clean(button.textContent)));
    if (!nextButton) return;
    if (activeMainTab() === "Personal") {
      nextButton.disabled = false;
      nextButton.removeAttribute("disabled");
      nextButton.ariaDisabled = "false";
      nextButton.classList.remove("pointer-events-none", "opacity-50");
    }
  }

  function personalSectionVisible() {
    const title = document.querySelector("[data-roseland-form-title]");
    if (title && visible(title)) return true;
    return Array.from(document.querySelectorAll("h1, h2, h3, p")).some((node) => {
      const text = clean(node.textContent);
      return visible(node) && /Additional personal details|Personal details/i.test(text);
    });
  }

  function clickTab(tabName) {
    const button = Array.from(document.querySelectorAll("button")).find((item) => {
      const text = clean(item.textContent).replace(/\s+\d+$/, "");
      return text === tabName && visible(item);
    });
    if (button) button.click();
  }

  function advanceFromPersonal() {
    if (!isAdmissionPage() || !personalSectionVisible()) return;
    window.setTimeout(() => {
      if (personalSectionVisible()) clickTab("Address");
    }, 120);
  }

  function wirePersonalAdvance() {
    if (window.__roselandPersonalAdvanceWired) return;
    window.__roselandPersonalAdvanceWired = true;
    const handle = (event) => {
      const target = event.target instanceof Element ? event.target.closest("button") : null;
      if (!target || !/next/i.test(clean(target.textContent))) return;
      advanceFromPersonal();
    };
    document.addEventListener("pointerdown", handle, true);
    document.addEventListener("click", handle, true);
    document.addEventListener("keyup", (event) => {
      if (event.key !== "Enter" && event.key !== " ") return;
      const target = event.target instanceof Element ? event.target.closest("button") : null;
      if (!target || !/next/i.test(clean(target.textContent))) return;
      advanceFromPersonal();
    }, true);
  }

  function patchFetch() {
    if (window.__roselandFetchPatched) return;
    window.__roselandFetchPatched = true;
    const originalFetch = window.fetch.bind(window);
    window.fetch = async function (input, init) {
      try {
        const url = typeof input === "string" ? input : input && input.url;
        const method = String((init && init.method) || "GET").toUpperCase();
        if (url && /\/api\/v1\/(public-admissions|admin\/erp\/admissions)/.test(url) && ["POST", "PUT", "PATCH"].includes(method) && init && typeof init.body === "string") {
          const payload = JSON.parse(init.body);
          const details = payload && typeof payload.details === "object" ? { ...payload.details } : { ...payload };
          const compositeName = buildCompositeStudentName();
          if (compositeName) {
            details["Name as per Aadhaar"] = compositeName;
            details["Name on marksheet"] = compositeName;
            details["Name on marksheet / LC"] = compositeName;
          }
          Array.from(document.querySelectorAll("[data-roseland-marathi-for]")).forEach((field) => {
            const key = field.getAttribute("data-roseland-marathi-for");
            if (!key) return;
            details[`${key} Marathi`] = field.value;
          });
          init = { ...init, body: JSON.stringify(payload && typeof payload.details === "object" ? { ...payload, details } : details) };
        }
      } catch (error) {
        console.warn("Roseland patch skipped fetch update", error);
      }
      return originalFetch(input, init);
    };
  }

  function patchActiveTabHints() {
    if (!isAdmissionPage()) return;
    replaceSemesterText();
    removeInstructionCopy();
    normalizeAdmissionHeading();
    if (isPublicAdmissionPage()) return;
    hideUnwantedFields();
    insertMarathiPairs();
    ensureMediumOptions();
    syncPersonalNext();
  }

  let scheduled = false;
  function scheduleRun() {
    if (scheduled) return;
    scheduled = true;
    window.requestAnimationFrame(() => {
      scheduled = false;
      run();
    });
  }

  function run() {
    ensureStyle();
    wirePersonalAdvance();
    patchFetch();
    patchActiveTabHints();
  }

  document.addEventListener("input", scheduleRun, true);
  document.addEventListener("change", scheduleRun, true);
  document.addEventListener("click", scheduleRun, true);
  window.addEventListener("popstate", scheduleRun);
  new MutationObserver(scheduleRun).observe(document.documentElement, { childList: true, subtree: true });
  run();
})();
