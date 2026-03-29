import { useState, useEffect } from "react";
import { API_BASE, DEFAULT_CMS } from "../config.js";

export const useCmsContent = () => {
  const [cms, setCms] = useState(DEFAULT_CMS);

  useEffect(() => {
    let cancelled = false;
    fetch(`${API_BASE}/content`)
      .then(r => r.json())
      .then(data => {
        if (cancelled || !data.success || !data.content) return;
        const c = data.content;
        // Deep merge: for each section, merge API values over defaults
        const merged = { ...DEFAULT_CMS };
        for (const section of Object.keys(c)) {
          if (c[section] && typeof c[section] === "object") {
            merged[section] = { ...DEFAULT_CMS[section], ...c[section] };
          } else if (c[section]) {
            merged[section] = c[section];
          }
        }
        setCms(merged);
      })
      .catch(() => { /* keep defaults */ });
    return () => { cancelled = true; };
  }, []);

  return cms;
};
