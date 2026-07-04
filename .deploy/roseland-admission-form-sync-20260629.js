(function () {
  const FORM_PATHS = ["/admission-form", "/admission", "/public-admissions"];
  const MAIN_TABS = ["Course", "Subjects", "Personal", "Address", "Reservation", "Qualification", "Documents", "Declaration"];
  const REQUIRED_LABELS = new Map([
    ["Admission To", "Admission Type"],
    ["Faculty", "Faculty"],
    ["Admission Class", "Class"],
    ["Last Name / Surname", "Surname (English)"],
    ["First Name", "First Name (English)"],
    ["Middle / Father Name", "Father / Husband Name (English)"],
    ["Mother Name", "Mother Name (English)"],
    ["Gender", "Gender"],
    ["Date of Birth", "Date of Birth"],
    ["Place of Birth", "Place of Birth"],
    ["Marital Status", "Marital Status"],
    ["Religion", "Religion"],
    ["Aadhar No", "Aadhaar No"],
    ["Nationality", "Nationality"],
    ["Category", "Caste Category"],
    ["Caste", "Caste"],
    ["Mobile No", "Mobile"],
    ["Occupation of Guardian", "Father / Guardian Occupation"],
    ["Annual Income of Guardian (in INR)", "Total Annual Income"],
  ]);

  const ALWAYS_KEEP_KEYS = new Set([
    "Admission Class",
    "Last Name / Surname",
    "First Name",
    "Middle / Father Name",
    "Mother Name",
    "Mobile No",
    "Aadhar No",
    "Gender",
    "Date of Birth",
    "Place of Birth",
    "Religion",
    "Caste",
    "Category",
    "Nationality",
    "Marital Status",
    "Occupation of Guardian",
    "Annual Income of Guardian (in INR)",
    "Residential Address",
    "Permanent Address",
    "Taluka",
    "District",
    "State",
    "Pin Code",
    "SSC Board/College",
    "SSC Seat No",
    "SSC Month",
    "SSC Year",
    "Obtained Marks",
    "Out of Marks",
    "Percentage",
    "College Name",
    "HSC / XIth Board/College",
    "Admission To",
    "Faculty",
  ]);

  const CUSTOM_FIELDS = [
    { key: "PEN", label: "PEN", section: "Personal Details" },
    { key: "ABC ID", label: "ABC ID", section: "Personal Details" },
  ];

  const MARATHI_NAME_FIELDS = new Map([
    ["Last Name / Surname", "Surname Marathi"],
    ["Last name", "Surname Marathi"],
    ["Surname (English)", "Surname Marathi"],
    ["First Name", "First Name Marathi"],
    ["First name", "First Name Marathi"],
    ["First Name (English)", "First Name Marathi"],
    ["Middle / Father Name", "Father or Husband Name Marathi"],
    ["Middle name", "Father or Husband Name Marathi"],
    ["Father / Husband Name (English)", "Father or Husband Name Marathi"],
    ["Father's First Name", "Father First Name Marathi"],
    ["Father first name", "Father First Name Marathi"],
    ["Mother Name", "Mother Name Marathi"],
    ["Mother first name", "Mother First Name Marathi"],
    ["Mother Name (English)", "Mother Name Marathi"],
    ["Mother's First Name", "Mother First Name Marathi"],
  ]);

  const MARATHI_LABELS = new Map([
    ["Surname Marathi", "Surname (Marathi)"],
    ["First Name Marathi", "First Name (Marathi)"],
    ["Father or Husband Name Marathi", "Father / Husband Name (Marathi)"],
    ["Father First Name Marathi", "Father First Name (Marathi)"],
    ["Mother Name Marathi", "Mother Name (Marathi)"],
    ["Mother First Name Marathi", "Mother First Name (Marathi)"],
  ]);

  const HIDE_FIELD_PATTERNS = [
    /^ABC ID$/i,
    /^Name on marksheet$/i,
    /^Name on marksheet \/ LC$/i,
    /^Name as per Aadhaar$/i,
    /^Name changed after last exam\??$/i,
    /^Blood group$/i,
    /^Married\??$/i,
  ];

  const TRANSLITERATION_API = "/api/v1/transliterate";
  const transliterationCache = new Map();

  const ROMAN_TO_MARATHI = [
    ["ksh", "क्ष"], ["dny", "ज्ञ"], ["gny", "ज्ञ"], ["shr", "श्र"], ["sh", "श"], ["chh", "छ"], ["ch", "च"],
    ["th", "थ"], ["dh", "ध"], ["kh", "ख"], ["gh", "घ"], ["ph", "फ"], ["bh", "भ"], ["aa", "आ"], ["ee", "ई"],
    ["ii", "ई"], ["oo", "ऊ"], ["ou", "औ"], ["ai", "ऐ"], ["au", "औ"], ["ng", "ङ"], ["ny", "ञ"],
    ["a", "अ"], ["b", "ब"], ["c", "क"], ["d", "द"], ["e", "ए"], ["f", "फ"], ["g", "ग"], ["h", "ह"],
    ["i", "इ"], ["j", "ज"], ["k", "क"], ["l", "ल"], ["m", "म"], ["n", "न"], ["o", "ओ"], ["p", "प"],
    ["q", "क"], ["r", "र"], ["s", "स"], ["t", "त"], ["u", "उ"], ["v", "व"], ["w", "व"], ["x", "क्स"],
    ["y", "य"], ["z", "झ"],
  ];

  function clean(text) {
    return String(text || "").replace(/\s+/g, " ").replace(/\s*\*+\s*$/, "").trim();
  }

  const runtimeState = window.__roselandAdmissionRuntime || (window.__roselandAdmissionRuntime = {
    verifiedAadhar: "",
    activeMainTab: "",
    scoped: Object.create(null),
  });

  function normalizedAadhar(value) {
    return String(value || "").replace(/\D+/g, "");
  }

  function currentVerifiedAadhar() {
    return normalizedAadhar(runtimeState.verifiedAadhar || "");
  }

  function storageKey(key, aadhar = currentVerifiedAadhar()) {
    const scope = normalizedAadhar(aadhar);
    return scope || "__draft__";
  }

  function storageGet(key, aadhar = currentVerifiedAadhar()) {
    const bucket = runtimeState.scoped[storageKey(key, aadhar)] || {};
    return String(bucket[key] || "");
  }

  function storageSet(key, value, aadhar = currentVerifiedAadhar()) {
    const next = String(value ?? "");
    const keyName = storageKey(key, aadhar);
    const bucket = runtimeState.scoped[keyName] || (runtimeState.scoped[keyName] = Object.create(null));
    if (next === "") {
      delete bucket[key];
      return;
    }
    bucket[key] = next;
  }

  function storageRemoveScoped(aadhar) {
    const scope = normalizedAadhar(aadhar);
    if (!scope) return;
    delete runtimeState.scoped[scope];
  }

  function clearVisibleFormValues() {
    Array.from(document.querySelectorAll("label")).forEach((label) => {
      const control = label.querySelector("input,select,textarea");
      if (!control || control.disabled) return;
      const original = clean(label.dataset.roselandOriginalLabel || label.textContent || control.getAttribute("data-field") || "");
      if (/aadhaar|aadhar/i.test(original)) return;
      if (control.tagName === "SELECT") {
        control.selectedIndex = 0;
      } else if (control.type === "checkbox" || control.type === "radio") {
        control.checked = false;
      } else {
        control.value = "";
      }
      control.dispatchEvent(new Event("input", { bubbles: true }));
      control.dispatchEvent(new Event("change", { bubbles: true }));
    });
    document.querySelectorAll("[data-roseland-field]").forEach((control) => {
      if (control.tagName === "SELECT") {
        control.selectedIndex = 0;
      } else if (control.type === "checkbox" || control.type === "radio") {
        control.checked = false;
      } else {
        control.value = "";
      }
      control.dispatchEvent(new Event("input", { bubbles: true }));
      control.dispatchEvent(new Event("change", { bubbles: true }));
    });
  }

  function saveVisibleFieldValues() {
    const aadhar = currentVerifiedAadhar();
    Array.from(document.querySelectorAll("label")).forEach((label) => {
      const control = label.querySelector("input,select,textarea");
      if (!control || control.disabled) return;
      const field = nearestField(label);
      if (field && !visible(field)) return;
      const original = clean(label.dataset.roselandOriginalLabel || label.textContent || control.getAttribute("data-field") || "");
      if (!original) return;
      const key = canonicalFieldKey(baseLabelText(label, original));
      const value = control.type === "checkbox" ? (control.checked ? "Yes" : "") : clean(control.value);
      storageSet(key, value, aadhar);
    });
    document.querySelectorAll("[data-roseland-field]").forEach((control) => {
      const key = clean(control.dataset.roselandField || "");
      if (!key) return;
      const value = control.type === "checkbox" ? (control.checked ? "Available with student" : "") : clean(control.value);
      storageSet(key, value, aadhar);
    });
  }

  function hydrateVisibleFieldValues() {
    const aadhar = currentVerifiedAadhar();
    if (!aadhar) return;
    Array.from(document.querySelectorAll("label")).forEach((label) => {
      const control = label.querySelector("input,select,textarea");
      if (!control || control.disabled) return;
      const field = nearestField(label);
      if (field && !visible(field)) return;
      if (control.value && control.value !== "") return;
      const original = clean(label.dataset.roselandOriginalLabel || label.textContent || control.getAttribute("data-field") || "");
      if (!original) return;
      const key = canonicalFieldKey(baseLabelText(label, original));
      const stored = storageGet(key, aadhar);
      if (!stored) return;
      if (control.type === "checkbox" || control.type === "radio") {
        control.checked = stored === "Yes" || stored === "Available with student";
      } else {
        control.value = stored;
      }
      control.dispatchEvent(new Event("input", { bubbles: true }));
      control.dispatchEvent(new Event("change", { bubbles: true }));
    });
    document.querySelectorAll("[data-roseland-field]").forEach((control) => {
      const key = clean(control.dataset.roselandField || "");
      if (!key) return;
      if ((control.type !== "checkbox" && control.value) || (control.type === "checkbox" && control.checked)) return;
      const stored = storageGet(key, aadhar);
      if (!stored) return;
      if (control.type === "checkbox") {
        control.checked = stored === "Available with student";
      } else {
        control.value = stored;
      }
    });
  }

  function onAdmissionPage() {
    return FORM_PATHS.some((path) => window.location.pathname.toLowerCase().includes(path));
  }

  function normalizeAdmissionRoute() {
    if (window.location.pathname.toLowerCase() === "/admission") {
      window.history.replaceState(null, "", "/admission-form");
      window.dispatchEvent(new PopStateEvent("popstate"));
      setTimeout(() => window.dispatchEvent(new Event("popstate")), 80);
    }
  }

  function getClassValue() {
    const labels = Array.from(document.querySelectorAll("label"));
    const classLabel = labels.find((label) => clean(label.textContent) === "Class" || clean(label.textContent) === "Admission Class");
    const wrapper = classLabel && (classLabel.closest("div") || classLabel.parentElement);
    const control = wrapper && wrapper.querySelector("select,input");
    return control ? String(control.value || "") : "";
  }

  function isJuniorCollege() {
    return /class\s*(xi|xii|11|12)|higher secondary|junior/i.test(getClassValue());
  }

  function nearestField(label) {
    let node = label;
    for (let i = 0; i < 5 && node; i += 1) {
      if (node.querySelector && node.querySelector("input,select,textarea")) return node;
      node = node.parentElement;
    }
    return label.closest("div") || label.parentElement;
  }

  function isAadhaarField(label, original) {
    const text = `${original} ${label.getAttribute("data-field") || ""} ${label.querySelector("input,select,textarea")?.getAttribute("data-field") || ""}`;
    return /aadhaar|aadhar/i.test(text);
  }

  function controlKey(label) {
    const control = label.querySelector("input,select,textarea");
    return clean(control?.getAttribute("data-field") || label.getAttribute("data-field") || "");
  }

  function baseLabelText(label, original) {
    const key = controlKey(label);
    if (key) return key;
    return original.replace(/i$/, "").trim();
  }

  function setAadhaarLabelVisible(label, field) {
    field.style.display = "";
    field.dataset.roselandAdmissionField = "required-aadhaar";
    const span = label.querySelector("span");
    if (span && span.firstChild && span.firstChild.nodeType === Node.TEXT_NODE) {
      span.firstChild.nodeValue = "Aadhaar number";
      return;
    }
    if (!label.querySelector("input,select,textarea")) {
      label.textContent = "Aadhaar number";
    }
  }

  function transliterateToMarathi(value) {
    const source = String(value || "").trim();
    if (!source) return "";
    return source.split(/\s+/).map((word) => {
      const lower = word.toLowerCase();
      let output = "";
      let index = 0;
      while (index < lower.length) {
        const match = ROMAN_TO_MARATHI.find(([latin]) => lower.slice(index).startsWith(latin));
        if (match) {
          output += match[1];
          index += match[0].length;
          continue;
        }
        output += word[index] || "";
        index += 1;
      }
      return output;
    }).join(" ");
  }

  function normalizeNameForTransliteration(word) {
    let normalized = clean(word).toLowerCase();
    if (!normalized) return "";
    normalized = normalized
      .replace(/^laxmi\b/g, "lakshmi")
      .replace(/^laxmibai\b/g, "lakshmibai")
      .replace(/^laxman\b/g, "lakshman")
      .replace(/x(?=m)/g, "ksh")
      .replace(/x(?=v|w|y)/g, "ksh");
    return normalized;
  }

  async function fetchWordSuggestions(word) {
    const source = clean(word);
    if (!source) return [];
    const normalized = normalizeNameForTransliteration(source);
    const cacheKey = normalized || source.toLowerCase();
    if (transliterationCache.has(cacheKey)) return transliterationCache.get(cacheKey);
    try {
      const query = `${TRANSLITERATION_API}?lang=mr&text=${encodeURIComponent(normalized || source)}`;
      const response = await fetch(query);
      const data = await response.json();
      const suggestions = Array.isArray(data?.data)
        ? data.data.filter(Boolean).slice(0, 5)
        : [];
      if (suggestions.length) {
        transliterationCache.set(cacheKey, suggestions);
        return suggestions;
      }
    } catch (error) {
      console.warn("Roseland transliteration fallback used", error);
    }
    const fallback = [transliterateToMarathi(source)];
    transliterationCache.set(cacheKey, fallback);
    return fallback;
  }

  async function fetchPhraseSuggestions(text) {
    const words = clean(text).split(/\s+/).filter(Boolean);
    if (!words.length) return [];
    const perWord = await Promise.all(words.map((word) => fetchWordSuggestions(word)));
    const maxSuggestions = Math.max(...perWord.map((list) => list.length), 1);
    const combined = [];
    for (let index = 0; index < Math.min(maxSuggestions, 5); index += 1) {
      combined.push(perWord.map((list) => list[index] || list[0] || "").filter(Boolean).join(" ").trim());
    }
    return Array.from(new Set(combined.filter(Boolean)));
  }

  function ensureSuggestionPanel(input, key) {
    const panelId = `roseland-marathi-suggestions-${key.replace(/[^a-z0-9]+/gi, "-").toLowerCase()}`;
    let panel = document.getElementById(panelId);
    if (!panel) {
      panel = document.createElement("div");
      panel.id = panelId;
      panel.dataset.roselandSuggestionPanel = key;
      panel.className = "relative z-20 mt-2 hidden rounded-xl border border-sky-200 bg-sky-50 p-2 shadow-sm";
      input.insertAdjacentElement("afterend", panel);
    }
    return panel;
  }

  function renderSuggestionPanel(panel, input, suggestions) {
    if (!suggestions.length) {
      panel.innerHTML = "";
      panel.classList.add("hidden");
      return;
    }
    panel.innerHTML = suggestions.map((option) => `<button type="button" class="mb-1 mr-1 inline-flex rounded-full border border-sky-200 bg-white px-3 py-1 text-sm font-semibold text-sky-800 hover:bg-sky-100" data-roseland-suggestion="${option.replace(/"/g, "&quot;")}">${option}</button>`).join("");
    panel.classList.remove("hidden");
    Array.from(panel.querySelectorAll("[data-roseland-suggestion]")).forEach((button) => {
      button.addEventListener("click", () => {
        input.value = button.getAttribute("data-roseland-suggestion") || "";
        input.dataset.autoFilled = "false";
        storageSet(input.dataset.roselandField, input.value);
        panel.classList.add("hidden");
      });
    });
  }

  function computedStudentName(details) {
    const first = clean(details["First Name"] || details["First Name (English)"] || "");
    const middle = clean(details["Middle / Father Name"] || details["Father / Husband Name (English)"] || details["Father's First Name"] || "");
    const last = clean(details["Last Name / Surname"] || details["Surname (English)"] || "");
    return [first, middle, last].filter(Boolean).join(" ").replace(/\s+/g, " ").trim();
  }

  function clearRoselandAdmissionState(nextAadhar = "") {
    const keepAadhar = normalizedAadhar(nextAadhar);
    const currentAadhar = currentVerifiedAadhar();
    if (keepAadhar && currentAadhar && keepAadhar !== currentAadhar) {
      clearVisibleFormValues();
      storageRemoveScoped(currentAadhar);
    }
    if (keepAadhar) {
      runtimeState.verifiedAadhar = keepAadhar;
      runtimeState.activeMainTab = "Course";
      return;
    }
    runtimeState.verifiedAadhar = "";
    runtimeState.activeMainTab = "";
  }

  function extractAadharFromRequest(url, body) {
    const fromUrl = String(url || "").match(/\/public-admissions\/by-aadhar\/([0-9]{12})/i);
    if (fromUrl) return fromUrl[1];
    const raw = body && typeof body === "object" ? (body.aadharNo || body.aadhaarNo || body["Aadhar No"] || (body.details && body.details["Aadhar No"])) : "";
    const digits = String(raw || "").replace(/\D+/g, "");
    return digits.length === 12 ? digits : "";
  }

  function labelAliases(label) {
    const text = clean(String(label || "")).toLowerCase();
    const aliases = new Set([text]);
    const add = (...values) => values.filter(Boolean).forEach((value) => aliases.add(clean(value).toLowerCase()));
    if (/^last name( \/ surname)?$|^surname( \(english\))?$/.test(text)) add("Last Name / Surname", "Last name", "Surname", "Surname (English)");
    if (/^first name( \(english\))?$/.test(text)) add("First Name", "First name", "First Name (English)");
    if (/^middle( \/ father)? name$|^father \/ husband name \(english\)$/.test(text)) add("Middle / Father Name", "Middle name", "Father / Husband Name (English)");
    if (/^father'?s? first name$/.test(text)) add("Father's First Name", "Father first name");
    if (/^mother name( \(english\))?$/.test(text)) add("Mother Name", "Mother Name (English)");
    if (/^mother'?s? first name$/.test(text)) add("Mother's First Name", "Mother first name");
    if (/^mobile no$|^mobile$|student mobile/i.test(text)) add("Mobile No", "Mobile", "Student mobile / WhatsApp");
    if (/parent.*mobile/i.test(text)) add("Parent's/Guardian's Mobile Number", "Parent mobile / WhatsApp");
    if (/^place of birth$/.test(text)) add("Place of Birth");
    if (/^date of birth$/.test(text)) add("Date of Birth");
    if (/^gender$/.test(text)) add("Gender");
    if (/^admission to$/.test(text)) add("Admission To");
    if (/^faculty$/.test(text)) add("Faculty");
    if (/^admission class$|^class$/.test(text)) add("Admission Class", "Class");
    return aliases;
  }

  function canonicalFieldKey(label) {
    const aliases = labelAliases(label);
    if (aliases.has("last name / surname") || aliases.has("surname")) return "Last Name / Surname";
    if (aliases.has("first name")) return "First Name";
    if (aliases.has("middle / father name") || aliases.has("middle name")) return "Middle / Father Name";
    if (aliases.has("father's first name") || aliases.has("father first name")) return "Father's First Name";
    if (aliases.has("mother name")) return "Mother Name";
    if (aliases.has("mother's first name") || aliases.has("mother first name")) return "Mother's First Name";
    if (aliases.has("mobile no") || aliases.has("mobile") || aliases.has("student mobile / whatsapp")) return "Mobile No";
    if (aliases.has("parent's/guardian's mobile number") || aliases.has("parent mobile / whatsapp")) return "Parent's/Guardian's Mobile Number";
    if (aliases.has("date of birth")) return "Date of Birth";
    if (aliases.has("place of birth")) return "Place of Birth";
    if (aliases.has("gender")) return "Gender";
    if (aliases.has("admission to")) return "Admission To";
    if (aliases.has("faculty")) return "Faculty";
    if (aliases.has("admission class") || aliases.has("class")) return "Admission Class";
    return clean(label);
  }

  function collectVisibleFieldValues() {
    const values = {};
    Array.from(document.querySelectorAll("label")).forEach((label) => {
      if (!visible(label)) return;
      const control = label.querySelector("input,select,textarea");
      if (!control || control.disabled) return;
      const field = nearestField(label);
      if (field && !visible(field)) return;
      const original = clean(label.dataset.roselandOriginalLabel || label.textContent || control.getAttribute("data-field") || "");
      if (!original) return;
      const key = canonicalFieldKey(baseLabelText(label, original));
      const value = control.type === "checkbox" ? (control.checked ? "Yes" : "") : clean(control.value);
      if (value) values[key] = value;
    });
    document.querySelectorAll("[data-roseland-field]").forEach((control) => {
      const key = clean(control.dataset.roselandField || "");
      if (!key) return;
      const value = control.type === "checkbox" ? (control.checked ? "Available with student" : "") : clean(control.value);
      if (value) values[key] = value;
    });
    const compositeName = computedStudentName(values);
    if (compositeName) {
      values["Name as per Aadhaar"] = compositeName;
      values["Name on marksheet"] = compositeName;
      values["Name on marksheet / LC"] = compositeName;
    }
    return values;
  }

  function fieldControl(label) {
    return label.querySelector("input,select,textarea");
  }

  function hideAndDisableField(field) {
    field.style.display = "none";
    field.dataset.roselandAdmissionField = "hidden-unwanted";
    field.querySelectorAll("input,select,textarea").forEach((control) => {
      control.required = false;
      control.removeAttribute("required");
      if (control.tagName === "SELECT") control.selectedIndex = 0;
      else if (control.type === "checkbox" || control.type === "radio") control.checked = false;
      else control.value = "Not Applicable";
      control.dispatchEvent(new Event("input", { bubbles: true }));
      control.dispatchEvent(new Event("change", { bubbles: true }));
    });
  }

  function renameAndHideFields() {
    const labels = Array.from(document.querySelectorAll("label"));
    labels.forEach((label) => {
      const original = clean(label.dataset.roselandOriginalLabel || label.textContent);
      if (!label.dataset.roselandOriginalLabel) label.dataset.roselandOriginalLabel = original;
      const baseLabel = baseLabelText(label, original);
      const field = nearestField(label);
      if (!field || field.closest("[data-roseland-required-panel]")) return;
      if (HIDE_FIELD_PATTERNS.some((pattern) => pattern.test(baseLabel) || pattern.test(original))) {
        hideAndDisableField(field);
        return;
      }
      if (baseLabel === "Aadhar No") {
        field.style.display = "none";
        field.dataset.roselandAdmissionField = "moved-to-start";
        return;
      }
      if (isAadhaarField(label, baseLabel)) {
        setAadhaarLabelVisible(label, field);
        return;
      }
      if (REQUIRED_LABELS.has(baseLabel)) {
        const friendly = REQUIRED_LABELS.get(baseLabel);
        if (label.childNodes.length === 1 && label.firstChild?.nodeType === Node.TEXT_NODE) {
          label.textContent = friendly;
        }
        field.style.display = "";
        field.dataset.roselandAdmissionField = "required";
      } else if (/^Document:/i.test(baseLabel)) {
        field.style.display = "none";
      }
    });
  }

  function extraInputBase() {
    return "mt-1 w-full rounded-xl bg-white px-3 py-2.5 border-2 border-slate-400 shadow-sm outline-none transition focus:border-sky-600 focus:ring-4 focus:ring-sky-100";
  }

  function ensureMarathiNameFields() {
    Array.from(document.querySelectorAll("label")).forEach((label) => {
      if (label.closest("[data-roseland-required-panel]")) return;
      const original = clean(label.dataset.roselandOriginalLabel || label.textContent);
      const baseLabel = baseLabelText(label, original);
      const marathiKey = MARATHI_NAME_FIELDS.get(baseLabel);
      const control = fieldControl(label);
      if (!marathiKey || !control || control.dataset.roselandMarathiBound === "true") return;
      const field = nearestField(label);
      if (!field || field.querySelector(`[data-roseland-field="${marathiKey}"]`)) return;
      control.dataset.roselandMarathiBound = "true";
      const wrapper = document.createElement("label");
      wrapper.className = label.className || "text-sm font-bold text-slate-700";
      wrapper.dataset.roselandInlineMarathi = "true";
      wrapper.style.marginTop = "0";
      const storedMarathi = storageGet(marathiKey) || "";
      wrapper.innerHTML = `${MARATHI_LABELS.get(marathiKey) || marathiKey}<input class="${extraInputBase()}" type="text" data-roseland-field="${marathiKey}" value="${storedMarathi.replace(/"/g, "&quot;")}">`;
      const host = field.parentElement;
      if (host) {
        host.style.display = "grid";
        host.style.gridTemplateColumns = "repeat(2, minmax(0, 1fr))";
        host.style.gap = "1rem";
        host.style.alignItems = "start";
        host.appendChild(wrapper);
      } else {
        field.insertAdjacentElement("afterend", wrapper);
      }
      const marathiInput = wrapper.querySelector("input");
      const suggestionPanel = ensureSuggestionPanel(marathiInput, marathiKey);
      const sync = async () => {
        const requestValue = clean(control.value);
        if (!requestValue) {
          marathiInput.value = "";
          marathiInput.dataset.autoFilled = "false";
          renderSuggestionPanel(suggestionPanel, marathiInput, []);
          return;
        }
        const suggestions = await fetchPhraseSuggestions(requestValue);
        renderSuggestionPanel(suggestionPanel, marathiInput, suggestions);
        if (!suggestions.length) return;
        if (clean(control.value) !== requestValue) return;
        if (!marathiInput.value || marathiInput.dataset.autoFilled === "true") {
          marathiInput.value = suggestions[0];
          marathiInput.dataset.autoFilled = "true";
          storageSet(marathiKey, marathiInput.value);
        }
      };
      control.addEventListener("blur", () => { void sync(); });
      control.addEventListener("change", () => { void sync(); });
      control.addEventListener("input", () => {
        suggestionPanel.classList.add("hidden");
      });
      marathiInput.addEventListener("focus", () => { void sync(); });
      marathiInput.addEventListener("click", () => { void sync(); });
      marathiInput.addEventListener("input", () => {
        marathiInput.dataset.autoFilled = "false";
        storageSet(marathiKey, marathiInput.value);
      });
    });
  }

  function ensureMediumInCourseSection() {
    const form = document.querySelector("form");
    if (!form || form.querySelector('[data-roseland-course-medium="true"]')) return;
    const courseControl = form.querySelector('select[data-field="Admission To"]');
    const classControl = form.querySelector('select[data-field="Admission Class"]');
    const anchor = classControl?.closest("label") || courseControl?.closest("label");
    if (!anchor) return;
    const wrapper = document.createElement("label");
    wrapper.dataset.roselandCourseMedium = "true";
    wrapper.className = anchor.className || "text-sm font-bold text-slate-700";
    const stored = storageGet("Medium") || "English";
    wrapper.innerHTML = `Medium<select class="${extraInputBase()}" data-roseland-field="Medium">
      ${["English", "Marathi", "Semi-English", "Arts", "Science", "Commerce"].map((option) => `<option ${option === stored ? "selected" : ""}>${option}</option>`).join("")}
    </select>`;
    anchor.insertAdjacentElement("afterend", wrapper);
    wrapper.querySelector("select").addEventListener("change", saveCustomValues);
  }

  function inputMarkup(field) {
    const base = "w-full rounded-lg border-2 border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 outline-none focus:border-sky-600 focus:ring-4 focus:ring-sky-100";
    const stored = storageGet(field.key) || field.value || "";
    if (field.type === "select") {
      return `<select class="${base}" data-roseland-field="${field.key}">${field.options.map((option) => `<option ${option === stored ? "selected" : ""}>${option}</option>`).join("")}</select>`;
    }
    if (field.type === "checkbox") {
      return `<label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700"><input type="checkbox" data-roseland-field="${field.key}" ${stored === "Available with student" ? "checked" : ""}> ${field.label}</label>`;
    }
    return `<input class="${base}" type="${field.type || "text"}" value="${stored.replace(/"/g, "&quot;")}" data-roseland-field="${field.key}">`;
  }

  function sectionMarkup(title, fields) {
    return `
      <section class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4" data-roseland-section="${title}">
        <h3 class="mb-3 text-sm font-black uppercase tracking-wide text-slate-700">${title}</h3>
        <div class="grid gap-3 md:grid-cols-2">
          ${fields.map((field) => `<div ${field.juniorOnly ? 'data-junior-only="true"' : ""}>
            ${field.type === "checkbox" ? inputMarkup(field) : `<label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">${field.label}</label>${inputMarkup(field)}`}
          </div>`).join("")}
        </div>
      </section>
    `;
  }

  function activeMainTab() {
    const buttons = Array.from(document.querySelectorAll("button"));
    const tabButtons = buttons
      .map((button) => ({ button, text: clean(button.textContent).replace(/\s+\d+$/, "") }))
      .filter((item) => MAIN_TABS.includes(item.text));
    const forced = clean(runtimeState.activeMainTab || "");
    if (forced && MAIN_TABS.includes(forced)) return forced;
    const active = tabButtons.find(({ button }) => {
      const className = String(button.className || "");
      return button.getAttribute("aria-selected") === "true" ||
        /\bbg-sky|\bborder-sky|\btext-sky|\bfrom-sky/i.test(className);
    });
    if (active) return active.text;
    const inferred = inferActiveMainTabFromContent();
    if (inferred) return inferred;
    return "";
  }

  function visibleNodeWithText(pattern) {
    return Array.from(document.querySelectorAll("h1,h2,h3,p,span,div,section")).find((node) => {
      if (!visible(node)) return false;
      if (node.querySelector("input,select,textarea,button") && !node.matches("section")) return false;
      return pattern.test(clean(node.textContent || ""));
    }) || null;
  }

  function inferActiveMainTabFromContent() {
    const visiblePersonalHeading = visibleNodeWithText(/^Personal details$/i);
    const visibleContactHeading = visibleNodeWithText(/^Contact details$/i);
    const visibleLastName = Array.from(document.querySelectorAll('input[data-field="Last name"], input[data-field="First name"], input[data-field="Father first name"]')).some((node) => visible(node));
    if ((visiblePersonalHeading || visibleContactHeading) && visibleLastName) return "Personal";
    if (visibleNodeWithText(/^Subject choice$/i)) return "Subjects";
    if (visibleNodeWithText(/^Admission details$/i) || visibleNodeWithText(/^Course selection$/i)) return "Course";
    if (visibleNodeWithText(/^Address details$/i) || visibleNodeWithText(/^Correspondence address$/i)) return "Address";
    if (visibleNodeWithText(/^Reservation details$/i) || visibleNodeWithText(/^Caste details$/i)) return "Reservation";
    if (visibleNodeWithText(/^Previous qualification$/i) || visibleNodeWithText(/^Qualification details$/i)) return "Qualification";
    if (visibleNodeWithText(/^Upload documents$/i) || visibleNodeWithText(/^Documents$/i)) return "Documents";
    if (visibleNodeWithText(/^Declaration$/i)) return "Declaration";
    return "";
  }

  function rememberMainTabClicks() {
    Array.from(document.querySelectorAll("button")).forEach((button) => {
      const text = clean(button.textContent).replace(/\s+\d+$/, "");
      if (!MAIN_TABS.includes(text) || button.dataset.roselandTabBound === "true") return;
      button.dataset.roselandTabBound = "true";
      button.addEventListener("click", () => {
        runtimeState.activeMainTab = text;
        setTimeout(enhance, 40);
      });
    });
  }

  function visible(node) {
    if (!node) return false;
    const style = window.getComputedStyle(node);
    return style.display !== "none" && style.visibility !== "hidden";
  }

  function clickTab(name) {
    const button = Array.from(document.querySelectorAll("button")).find((node) => clean(node.textContent).replace(/\s+\d+$/, "") === name);
    if (!button) return;
    button.disabled = false;
    button.removeAttribute("disabled");
    button.ariaDisabled = "false";
    button.classList.remove("pointer-events-none", "opacity-50");
    button.click();
    button.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, view: window }));
  }

  function forceActiveTab(name) {
    if (!MAIN_TABS.includes(name)) return;
    runtimeState.activeMainTab = name;
    clickTab(name);
  }

  function ensureCustomPanel() {
    const form = document.querySelector("form");
    if (!form || form.querySelector("[data-roseland-required-panel]")) return;
    const bySection = CUSTOM_FIELDS.reduce((map, field) => {
      const existing = Array.from(document.querySelectorAll("label")).find((label) => {
        const original = clean(label.dataset.roselandOriginalLabel || label.textContent || "");
        return original === field.label || baseLabelText(label, original) === field.label;
      });
      if (existing) return map;
      if (!map.has(field.section)) map.set(field.section, []);
      map.get(field.section).push(field);
      return map;
    }, new Map());
    const panel = document.createElement("div");
    panel.dataset.roselandRequiredPanel = "true";
    panel.className = "my-5 space-y-4 rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-sm";
    const sections = Array.from(bySection.keys());
    panel.innerHTML = `
      ${sections.map((section) => sectionMarkup(section, bySection.get(section))).join("")}
    `;
    const footer = Array.from(form.querySelectorAll("button")).find((button) => /next|submit|save/i.test(button.textContent || ""));
    const footerWrap = footer ? footer.closest("div") : null;
    form.insertBefore(panel, footerWrap || null);
    panel.addEventListener("input", saveCustomValues);
    panel.addEventListener("change", saveCustomValues);
  }

  function updateJuniorVisibility() {
    const junior = isJuniorCollege();
    document.querySelectorAll("[data-junior-only]").forEach((node) => {
      node.style.display = junior ? "" : "none";
    });
    const panel = document.querySelector("[data-roseland-required-panel]");
    if (panel) {
      const activeTab = activeMainTab();
      panel.style.display = activeTab === "Personal" ? "" : "none";
    }
  }

  function personalSectionVisible() {
    const panel = document.querySelector("[data-roseland-required-panel]");
    const inferredTab = activeMainTab();
    if (inferredTab !== "Personal") return false;
    if (visible(panel)) return true;
    return !!inferActiveMainTabFromContent();
  }

  function visibleNextButton() {
    return Array.from(document.querySelectorAll("button")).find((button) => /^next$/i.test(clean(button.textContent)) && visible(button));
  }

  function personalValidationRules() {
    return [
      { keys: ["Last Name / Surname", "Surname (English)"], label: "Last Name / Surname", message: "Last name is required.", validate: (value) => value !== "" },
      { keys: ["First Name", "First Name (English)"], label: "First Name", message: "First name is required.", validate: (value) => value !== "" },
      { keys: ["Date of Birth"], label: "Date of Birth", message: "Date of birth is required.", validate: (value) => value !== "" },
      { keys: ["Gender"], label: "Gender", message: "Gender is required.", validate: (value) => value !== "" },
      { keys: ["Father's First Name", "Middle / Father Name", "Father / Husband Name (English)"], label: "Father's First Name", message: "Father first name is required.", validate: (value) => value !== "" },
      { keys: ["Place of Birth"], label: "Place of Birth", message: "Place of birth is required.", validate: (value) => value !== "" },
      { keys: ["Mobile No", "Mobile", "Student mobile / WhatsApp"], label: "Mobile No", message: "Enter 10 digit mobile number.", validate: (value) => value.replace(/\D+/g, "").length === 10 },
      { keys: ["Parent's/Guardian's Mobile Number", "Parent mobile / WhatsApp"], label: "Parent's/Guardian's Mobile Number", message: "Enter 10 digit parent mobile number.", validate: (value) => value.replace(/\D+/g, "").length === 10 },
    ];
  }

  function personalSectionBlocks() {
    return Array.from(document.querySelectorAll("section")).filter((section) => {
      if (!visible(section)) return false;
      const heading = section.querySelector("h1,h2,h3");
      const text = clean(heading?.textContent || "");
      return /^(Personal details|Contact details)$/i.test(text);
    });
  }

  function visiblePersonalRequiredControls() {
    return personalSectionBlocks().flatMap((section) =>
      Array.from(section.querySelectorAll("input,select,textarea")).filter((control) => {
        if (control.disabled || !control.required || !visible(control)) return false;
        const field = nearestField(control.closest("label") || control.parentElement);
        return !field || visible(field);
      })
    );
  }

  function controlHasValue(control) {
    if (!control || control.disabled) return true;
    if (control.type === "checkbox" || control.type === "radio") return control.checked;
    if (control.tagName === "SELECT") {
      const value = clean(control.value);
      if (value) return true;
      return control.selectedIndex > 0;
    }
    return clean(control.value) !== "";
  }

  function visiblePersonalRequiredFieldsValid() {
    return visiblePersonalRequiredControls().every(controlHasValue);
  }

  function personalControlLabel(control) {
    const label = control?.closest("label");
    const original = clean(label?.dataset.roselandOriginalLabel || control?.getAttribute("data-field") || "");
    return baseLabelText(label || control, original) || clean(control?.getAttribute("data-field") || "Required field");
  }

  function personalControlRule(control) {
    const key = canonicalFieldKey(personalControlLabel(control));
    switch (key) {
      case "Last Name / Surname":
        return { label: "Last Name / Surname", message: "Last name is required.", validate: (value) => value !== "" };
      case "First Name":
        return { label: "First Name", message: "First name is required.", validate: (value) => value !== "" };
      case "Middle / Father Name":
      case "Father's First Name":
        return { label: "Father's First Name", message: "Father first name is required.", validate: (value) => value !== "" };
      case "Gender":
        return { label: "Gender", message: "Gender is required.", validate: (value) => value !== "" };
      case "Date of Birth":
        return { label: "Date of Birth", message: "Date of birth is required.", validate: (value) => value !== "" };
      case "Place of Birth":
        return { label: "Place of Birth", message: "Place of birth is required.", validate: (value) => value !== "" };
      case "Mobile No":
        return { label: "Mobile No", message: "Enter 10 digit mobile number.", validate: (value) => value.replace(/\D+/g, "").length === 10 };
      case "Parent's/Guardian's Mobile Number":
        return { label: "Parent's/Guardian's Mobile Number", message: "Enter 10 digit parent mobile number.", validate: (value) => value.replace(/\D+/g, "").length === 10 };
      default:
        return { label: personalControlLabel(control), message: "This field is required.", validate: (value) => value !== "" };
    }
  }

  function validateVisiblePersonalControls(showTouchedOnly = true, forceShowAll = false) {
    const missing = [];
    visiblePersonalRequiredControls().forEach((control) => {
      const value = clean(control.type === "checkbox" ? (control.checked ? "Yes" : "") : control.value);
      const rule = personalControlRule(control);
      const touched = control.dataset.roselandTouched === "true";
      const shouldShow = forceShowAll || !showTouchedOnly || touched;
      const valid = rule.validate(value);
      setValidationError(control, rule.label, shouldShow && !valid ? rule.message : "");
      if (!valid) missing.push(`${rule.label}: ${rule.message}`);
    });
    return Array.from(new Set(missing));
  }

  function canonicalFieldAliases(label) {
    return labelAliases(label);
  }

  function findVisibleControlForKeys(keys) {
    const lowerKeys = keys.map((key) => clean(key).toLowerCase());
    const labels = Array.from(document.querySelectorAll("label"));
    for (const label of labels) {
      const control = label.querySelector("input,select,textarea");
      if (!control || control.disabled) continue;
      const field = nearestField(label);
      if (field && !visible(field)) continue;
      const original = clean(label.dataset.roselandOriginalLabel || label.textContent || control.getAttribute("data-field") || "");
      if (!original) continue;
      const aliases = canonicalFieldAliases(baseLabelText(label, original));
      if (Array.from(aliases).some((alias) => lowerKeys.includes(alias))) return control;
    }
    return null;
  }

  function findVisibleControlsForKeys(keys) {
    const lowerKeys = keys.map((key) => clean(key).toLowerCase());
    const labels = Array.from(document.querySelectorAll("label"));
    return labels.reduce((controls, label) => {
      const control = label.querySelector("input,select,textarea");
      if (!control || control.disabled) return controls;
      const field = nearestField(label);
      if (field && !visible(field)) return controls;
      const original = clean(label.dataset.roselandOriginalLabel || control.getAttribute("data-field") || "");
      if (!original) return controls;
      const aliases = canonicalFieldAliases(baseLabelText(label, original));
      if (Array.from(aliases).some((alias) => lowerKeys.includes(alias))) controls.push(control);
      return controls;
    }, []);
  }

  function cleanupLegacyValidationMarkup() {
    document.querySelectorAll('[data-roseland-error-for]').forEach((node) => {
      const previous = node.previousElementSibling;
      const next = node.nextElementSibling;
      const owner = previous?.matches?.('label') ? previous : next?.matches?.('label') ? next : null;
      if (!owner) node.remove();
    });
    document.querySelectorAll("label").forEach((label) => {
      const control = label.querySelector("input,select,textarea");
      if (!control) return;
      Array.from(label.querySelectorAll("span,div,p")).forEach((node) => {
        if (node.querySelector("input,select,textarea,button")) return;
        if (node.dataset.roselandErrorFor) return;
        const text = clean(node.textContent || "");
        const className = String(node.className || "");
        if (!text) return;
        if (/required|enter 10 digit|date of birth|place of birth|gender/i.test(text) && /text-rose-600|text-red/i.test(className)) {
          node.remove();
        }
      });
      const original = clean(label.dataset.roselandOriginalLabel || "");
      if (/required\.?$/i.test(original) || /enter 10 digit/i.test(original)) {
        label.dataset.roselandOriginalLabel = clean(control.getAttribute("data-field") || original.split(/required|enter 10 digit/i)[0]);
      }
    });
  }

  function ensureValidationLabel(control, labelText) {
    const key = clean(labelText).toLowerCase().replace(/[^a-z0-9]+/g, "-");
    const host = control.closest("label") || control.parentElement;
    if (!host) return null;
    const existingId = control.dataset.roselandErrorId || "";
    let message = existingId ? document.getElementById(existingId) : null;
    if (!message) {
      message = host.nextElementSibling && host.nextElementSibling.matches?.(`[data-roseland-error-for="${key}"]`)
        ? host.nextElementSibling
        : null;
    }
    if (!message) {
      message = document.createElement("div");
      const messageId = `roseland-error-${key}-${Math.random().toString(36).slice(2, 8)}`;
      message.id = messageId;
      control.dataset.roselandErrorId = messageId;
      message.dataset.roselandErrorFor = key;
      message.className = "mt-1 hidden text-xs font-bold text-rose-600";
      host.insertAdjacentElement("afterend", message);
    } else if (!control.dataset.roselandErrorId && message.id) {
      control.dataset.roselandErrorId = message.id;
    }
    return message;
  }

  function setValidationError(control, labelText, messageText) {
    if (!control) return;
    const errorNode = ensureValidationLabel(control, labelText);
    if (!errorNode) return;
    if (!messageText) {
      errorNode.textContent = "";
      errorNode.classList.add("hidden");
      control.classList.remove("border-rose-500", "ring-rose-100");
      return;
    }
    errorNode.textContent = messageText;
    errorNode.classList.remove("hidden");
    control.classList.add("border-rose-500", "ring-rose-100");
  }

  function validatePersonalFields(showTouchedOnly = true, forceShowAll = false) {
    const directMissing = validateVisiblePersonalControls(showTouchedOnly, forceShowAll);
    if (directMissing.length) return directMissing;
    const missing = [];
    personalValidationRules().forEach((rule) => {
      const controls = findVisibleControlsForKeys(rule.keys);
      if (!controls.length) return;
      const primaryControl = controls[0];
      const value = controls
        .map((control) => clean(control.type === "checkbox" ? (control.checked ? "Yes" : "") : control.value))
        .find(Boolean) || "";
      const valid = rule.validate(value);
      const touched = controls.some((control) => control.dataset.roselandTouched === "true");
      const shouldShow = forceShowAll || !showTouchedOnly || touched;
      controls.forEach((control) => setValidationError(control, rule.label, ""));
      setValidationError(primaryControl, rule.label, shouldShow && !valid ? rule.message : "");
      if (!valid) missing.push(`${rule.label}: ${rule.message}`);
    });
    return Array.from(new Set(missing));
  }

  function bindPersonalValidation() {
    visiblePersonalRequiredControls().forEach((control) => {
      if (control.dataset.roselandValidationBound === "true") return;
      control.dataset.roselandValidationBound = "true";
      const onValidate = () => syncPersonalNext();
      control.addEventListener("blur", () => {
        control.dataset.roselandTouched = "true";
        validatePersonalFields(true, false);
        onValidate();
      });
      control.addEventListener("change", () => {
        control.dataset.roselandTouched = "true";
        validatePersonalFields(true, false);
        onValidate();
      });
      control.addEventListener("input", () => {
        validatePersonalFields(true, false);
        onValidate();
      });
    });
  }

  function resetPersonalValidationState() {
    visiblePersonalRequiredControls().forEach((control) => {
      delete control.dataset.roselandTouched;
      const rule = personalControlRule(control);
      setValidationError(control, rule.label, "");
    });
  }

  function clearSuggestionPanels() {
    document.querySelectorAll("[data-roseland-suggestion-panel]").forEach((panel) => {
      panel.innerHTML = "";
      panel.classList.add("hidden");
    });
  }

  function verifiedAadhaarInput() {
    return Array.from(document.querySelectorAll("label input, input")).find((control) => {
      const label = control.closest("label");
      const labelText = clean(label?.dataset.roselandOriginalLabel || label?.textContent || control.getAttribute("data-field") || control.getAttribute("placeholder") || "");
      return /aadhaar|aadhar/i.test(labelText) && visible(control);
    }) || null;
  }

  function resetForVerifiedAadhaar(aadhar) {
    const digits = normalizedAadhar(aadhar);
    if (digits) storageRemoveScoped(digits);
    clearRoselandAdmissionState(digits);
    clearVisibleFormValues();
    clearSuggestionPanels();
    resetPersonalValidationState();
    if (digits) {
      const input = verifiedAadhaarInput();
      if (input) {
        input.value = digits;
        input.dispatchEvent(new Event("input", { bubbles: true }));
      }
    }
    syncPersonalNext();
  }

  function bindAadhaarStartHandlers() {
    const input = verifiedAadhaarInput();
    if (!input || input.dataset.roselandVerifyBound === "true") return;
    input.dataset.roselandVerifyBound = "true";
    const verifyButton = Array.from(document.querySelectorAll("button")).find((button) => /verify aadhaar/i.test(clean(button.textContent)));
    const prepare = () => {
      const digits = normalizedAadhar(input.value);
      if (digits.length === 12) resetForVerifiedAadhaar(digits);
    };
    if (verifyButton && verifyButton.dataset.roselandVerifyButtonBound !== "true") {
      verifyButton.dataset.roselandVerifyButtonBound = "true";
      verifyButton.addEventListener("click", prepare, true);
    }
    input.addEventListener("keydown", (event) => {
      if (event.key === "Enter") prepare();
    }, true);
  }

  function syncPersonalNext() {
    if (!personalSectionVisible()) return;
    const nextButton = visibleNextButton();
    if (!nextButton) return;
    cleanupLegacyValidationMarkup();
    bindPersonalValidation();
    const validByRules = validatePersonalFields(true, false).length === 0;
    const validByRequiredControls = visiblePersonalRequiredFieldsValid();
    const valid = validByRules && validByRequiredControls;
    nextButton.disabled = !valid;
    nextButton.ariaDisabled = valid ? "false" : "true";
    if (valid) {
      nextButton.removeAttribute("disabled");
      nextButton.classList.remove("pointer-events-none", "opacity-50", "opacity-40", "opacity-30", "opacity-25");
    } else {
      nextButton.setAttribute("disabled", "disabled");
      nextButton.classList.add("opacity-40");
    }
  }

  function schedulePersonalNextRefresh() {
    if (window.__roselandPersonalNextRefreshScheduled) return;
    window.__roselandPersonalNextRefreshScheduled = true;
    const tick = () => {
      window.__roselandPersonalNextRefreshScheduled = false;
      if (personalSectionVisible()) syncPersonalNext();
    };
    requestAnimationFrame(tick);
    setTimeout(tick, 120);
  }

  function missingRequiredFields() {
    return validateVisiblePersonalControls(false, true);
  }

  function normalizeAdmissionHeading() {
    Array.from(document.querySelectorAll("h1,h2,h3,p,span,div")).forEach((node) => {
      if (node.querySelector("input,select,textarea,button")) return;
      const text = clean(node.textContent);
      if (text === "Course" && activeMainTab() === "Course") {
        node.textContent = "Admission form";
      } else if (text === "Course selection") {
        node.textContent = "Admission details";
      }
    });
  }

  function syncForcedTabVisibility() {
    const activeTab = activeMainTab();
    const subjectSection = Array.from(document.querySelectorAll("section,h2,div")).find((node) => /Subject choice/i.test(clean(node.textContent)));
    const panel = document.querySelector("[data-roseland-required-panel]");
    if (panel) panel.style.display = activeTab === "Personal" ? "" : "none";
    if (subjectSection && activeTab === "Personal") {
      const section = subjectSection.closest("section") || subjectSection.parentElement;
      if (section) section.style.display = "none";
    }
    if (subjectSection && activeTab !== "Personal") {
      const section = subjectSection.closest("section") || subjectSection.parentElement;
      if (section) section.style.display = "";
    }
  }

  function removeTabInstructions() {
    const instructionPatterns = [
      /Complete the required fields on this tab to enable Next/i,
      /^Admission To:\s*Select course\.?$/i,
      /^Admission Class:\s*Select class\.?$/i,
      /Select the course and class first\. Subject options will appear/i,
      /^Additional personal details$/i,
      /^PEN is kept here\. Marathi names appear next to the matching English name fields\.$/i,
    ];
    Array.from(document.querySelectorAll("p,li,div,span")).forEach((node) => {
      if (node.closest("label,button,select,option,input,textarea,[data-roseland-required-panel]")) return;
      const text = clean(node.textContent);
      if (!text) return;
      if (text.length > 140 || node.querySelector("input,button,select,textarea,a")) return;
      if (instructionPatterns.some((pattern) => pattern.test(text))) {
        node.style.display = "none";
      }
    });
  }

  function saveCustomValues() {
    document.querySelectorAll("[data-roseland-field]").forEach((control) => {
      const key = control.dataset.roselandField;
      const value = control.type === "checkbox" ? (control.checked ? "Available with student" : "Will submit later") : control.value;
      storageSet(key, value);
    });
  }

  function customValues() {
    const values = {};
    document.querySelectorAll("[data-roseland-field]").forEach((control) => {
      const key = control.dataset.roselandField;
      values[key] = control.type === "checkbox" ? (control.checked ? "Available with student" : "Will submit later") : control.value;
    });
    values["Form Variant"] = isJuniorCollege() ? "Junior College Admission Form" : "School Admission Form";
    return values;
  }

  function withDerivedMarathiNames(details) {
    const next = { ...details };
    [
      ["Last Name / Surname", "Surname Marathi"],
      ["First Name", "First Name Marathi"],
      ["Middle / Father Name", "Father or Husband Name Marathi"],
      ["Father's First Name", "Father First Name Marathi"],
      ["Mother Name", "Mother Name Marathi"],
      ["Mother's First Name", "Mother First Name Marathi"],
    ].forEach(([englishKey, marathiKey]) => {
      if (!next[marathiKey] && next[englishKey]) {
        next[marathiKey] = transliterateToMarathi(next[englishKey]);
      }
    });
    [
      "Name on marksheet",
      "Name on marksheet / LC",
      "Name as per Aadhaar",
      "Name changed after last exam?",
      "Blood group",
      "Married?",
    ].forEach((key) => delete next[key]);
    return next;
  }

  function filteredDetails(source) {
    const out = {};
    const domValues = collectVisibleFieldValues();
    ALWAYS_KEEP_KEYS.forEach((key) => {
      if (Object.prototype.hasOwnProperty.call(source, key)) out[key] = source[key];
    });
    Object.entries(source).forEach(([key, value]) => {
      if (key.startsWith("Subject:") || key.startsWith("SubjectOrder:") || ["subjects", "qualifications", "student_photo_data_url", "student_signature_data_url"].includes(key)) {
        out[key] = value;
      }
    });
    Object.assign(out, domValues);
    Object.assign(out, customValues());
    Object.assign(out, withDerivedMarathiNames(out));
    const compositeName = computedStudentName(out);
    if (compositeName) {
      out["Name as per Aadhaar"] = compositeName;
      out["Name on marksheet"] = compositeName;
      out["Name on marksheet / LC"] = compositeName;
    }
    if (!out["Admission Type"] && source["Admission To"]) out["Admission Type"] = source["Admission To"];
    if (!out["Faculty"] && source.Faculty) out["Faculty"] = source.Faculty;
    if (!out["Medium"]) out["Medium"] = "English";
    delete out["Admission Form No"];
    delete out["Academic Year"];
    delete out["Admission Date"];
    [
      "Receipt No",
      "Receipt Date",
      "Admission Committee Remarks",
      "Committee Signature",
      "Committee Date",
      "Parent Guardian Signature",
      "Student Signature",
      "Principal Signature",
      "Local Mobile",
      "Name on marksheet",
      "Name on marksheet / LC",
      "Name as per Aadhaar",
      "Name changed after last exam?",
      "Blood group",
      "Married?",
    ].forEach((key) => delete out[key]);
    return out;
  }

  function enrichedDetails(source) {
    return withDerivedMarathiNames({ ...source, ...customValues() });
  }

  function patchFetch() {
    if (window.__roselandAdmissionFetchPatched) return;
    window.__roselandAdmissionFetchPatched = true;
    const originalFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
      try {
        const url = typeof input === "string" ? input : input && input.url;
        const method = String((init && init.method) || "GET").toUpperCase();
        const parsedBody = init && typeof init.body === "string" ? JSON.parse(init.body) : null;
        if (url && /\/api\/v1\/public-admissions\/by-aadhar\/[0-9]{12}/.test(url) && method === "GET") {
          const nextAadhar = extractAadharFromRequest(url, null);
          const currentAadhar = currentVerifiedAadhar();
          if (nextAadhar && nextAadhar !== currentAadhar) clearRoselandAdmissionState(nextAadhar);
        }
        if (url && /\/api\/v1\/public-admissions(?:\/edit-otp\/request)?/.test(url) && ["POST", "PUT", "PATCH"].includes(method) && parsedBody) {
          const nextAadhar = extractAadharFromRequest(url, parsedBody);
          const currentAadhar = currentVerifiedAadhar();
          if (nextAadhar && nextAadhar !== currentAadhar) clearRoselandAdmissionState(nextAadhar);
        }
        if (url && /\/api\/v1\/public-admissions/.test(url) && ["POST", "PUT", "PATCH"].includes(method) && init && typeof init.body === "string") {
          const body = parsedBody || JSON.parse(init.body);
          const details = body.details && typeof body.details === "object" ? body.details : body;
          const nextDetails = filteredDetails(details);
          const nextBody = body.details && typeof body.details === "object" ? { ...body, details: nextDetails } : nextDetails;
          init = { ...init, body: JSON.stringify(nextBody) };
        } else if (url && /\/api\/v1\/admin\/erp\/admissions/.test(url) && ["POST", "PUT", "PATCH"].includes(method) && init && typeof init.body === "string") {
          const body = parsedBody || JSON.parse(init.body);
          if (body.details && typeof body.details === "object") {
            init = { ...init, body: JSON.stringify({ ...body, details: enrichedDetails(body.details) }) };
          }
        }
      } catch (error) {
        console.warn("Roseland admission sync skipped fetch filtering", error);
      }
      return originalFetch(input, init);
    };
  }

  async function saveDraftToDatabase(snapshot = null) {
    const current = snapshot || collectVisibleFieldValues();
    const aadhar = normalizedAadhar(current["Aadhar No"] || currentVerifiedAadhar());
    if (aadhar.length !== 12) return null;
    clearRoselandAdmissionState(aadhar);
    const details = filteredDetails(current);
    details["Aadhar No"] = aadhar;
    const payload = {
      draft: true,
      details,
    };
    const editToken = storageGet("editToken", aadhar);
    if (editToken) payload.editToken = editToken;
    const response = await window.fetch("/api/v1/public-admissions", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(data?.error || "Unable to save draft");
    }
    const application = data?.data || {};
    if (application.id) storageSet("applicationId", application.id, aadhar);
    if (application.numericId) storageSet("applicationNumericId", String(application.numericId), aadhar);
    storageSet("draftSavedAt", new Date().toISOString(), aadhar);
    return application;
  }

  function enhance() {
    normalizeAdmissionRoute();
    patchFetch();
    if (!onAdmissionPage()) return;
    cleanupLegacyValidationMarkup();
    bindAadhaarStartHandlers();
    ensureCustomPanel();
    rememberMainTabClicks();
    renameAndHideFields();
    ensureMediumInCourseSection();
    ensureMarathiNameFields();
    updateJuniorVisibility();
    bindPersonalValidation();
    syncPersonalNext();
    syncForcedTabVisibility();
    normalizeAdmissionHeading();
    removeTabInstructions();
  }

  document.addEventListener("change", (event) => {
    if (event.target && event.target.matches("select,input")) {
      setTimeout(() => {
        enhance();
        schedulePersonalNextRefresh();
      }, 40);
    }
  });
  document.addEventListener("input", (event) => {
    if (event.target && event.target.matches("input,select,textarea")) {
      setTimeout(() => {
        syncPersonalNext();
        schedulePersonalNextRefresh();
      }, 20);
    }
  });
  document.addEventListener("click", (event) => {
    const button = event.target && event.target.closest ? event.target.closest("button") : null;
    if (button && /^next$/i.test(clean(button.textContent)) && personalSectionVisible()) {
      const missing = missingRequiredFields();
      if (missing.length) {
        event.preventDefault();
        event.stopPropagation();
        syncPersonalNext();
        window.alert(missing.join("\n"));
        return;
      }
      const snapshot = collectVisibleFieldValues();
      Object.assign(snapshot, customValues());
      (async () => {
        try {
          await saveDraftToDatabase(snapshot);
        } catch (error) {
          window.alert(error?.message || "Unable to save admission draft right now.");
        }
      })();
      return;
    }
    setTimeout(enhance, 80);
    setTimeout(schedulePersonalNextRefresh, 100);
  });
  window.addEventListener("popstate", () => setTimeout(enhance, 80));
  let scheduled = false;
  function scheduleEnhance() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
      scheduled = false;
      enhance();
    });
  }
  new MutationObserver(() => scheduleEnhance()).observe(document.documentElement, { childList: true, subtree: true });
  enhance();
})();

