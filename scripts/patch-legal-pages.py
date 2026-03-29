#!/usr/bin/env python3
"""Patch the React bundle to add Impressum & Datenschutz pages."""
import sys

BUNDLE = '/home/user/Ultra-geheimer-Code-nicht-klicken-/website/assets/index-DHPzOD_b.js'

with open(BUNDLE, 'r') as f:
    c = f.read()

patches_applied = 0

# ────────────────────────────────────────────────────
# 1. Add Impressum & Datenschutz page components
#    Insert them right before the page dict (let d={home:...)
# ────────────────────────────────────────────────────

PAGE_DICT_MARKER = 'let d={home:'
idx = c.find(PAGE_DICT_MARKER)
if idx < 0:
    print("ERROR: Could not find page dict marker")
    sys.exit(1)

# Impressum component - reads legal data from CMS
impressum_component = r"""var _ImpPage=function(_pp){var _t=_pp.t,_cms=_pp.cms||{},_sp=_pp.setPage,_lg=_cms.legal||{};var _cn=_lg.company_name||"SR-Homes Immobilien GmbH",_fn=_lg.fn_number||"FN 4556571 i",_uid=_lg.uid_number||"ATU 71268923",_ceo=_lg.ceo_name||"",_court=_lg.court||"Landesgericht Salzburg",_trade=_lg.trade_license||"Konzessionierter Immobilientreuh\u00E4nder",_auth=_lg.authority||"Magistrat der Stadt Salzburg",_extra=_lg.impressum_extra||"",_ct=_cms.contact||{};return(0,O.jsxs)("div",{children:[(0,O.jsx)("section",{className:"pt-32 pb-16 md:pt-40 md:pb-20",style:{background:_t.bgAlt},children:(0,O.jsx)("div",{className:"max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16",children:(0,O.jsx)("h1",{className:"font-display text-4xl md:text-5xl font-bold",style:{color:_t.text},children:"Impressum"})})}),(0,O.jsx)("section",{className:"py-16 md:py-24",style:{background:_t.bg},children:(0,O.jsx)("div",{className:"max-w-[800px] mx-auto px-6 md:px-12",children:(0,O.jsxs)("div",{className:"prose prose-lg",style:{color:_t.textMuted},children:[(0,O.jsx)("h2",{className:"text-2xl font-bold mb-6",style:{color:_t.text},children:"Angaben gem\u00E4\u00DF \u00A7 5 ECG"}),(0,O.jsxs)("div",{className:"space-y-4 text-sm leading-relaxed",children:[(0,O.jsxs)("p",{children:[(0,O.jsx)("strong",{style:{color:_t.text},children:_cn}),(0,O.jsx)("br",{}),(_ct.address||"Innsbrucker Bundesstra\u00DFe 73/Top 5\nA-5020 Salzburg, \u00D6sterreich").split("\n").map(function(l,i){return(0,O.jsxs)("span",{children:[i>0?(0,O.jsx)("br",{}):null,l]},i)})]}),(0,O.jsxs)("p",{children:[(0,O.jsx)("strong",{style:{color:_t.text},children:"Telefon: "}),_ct.phone||"+43 664 2600 930",(0,O.jsx)("br",{}),(0,O.jsx)("strong",{style:{color:_t.text},children:"E-Mail: "}),_ct.email||"office@sr-homes.at"]}),_ceo?(0,O.jsxs)("p",{children:[(0,O.jsx)("strong",{style:{color:_t.text},children:"Gesch\u00E4ftsf\u00FChrer: "}),_ceo]}):null,(0,O.jsxs)("p",{children:[(0,O.jsx)("strong",{style:{color:_t.text},children:"Firmenbuchnummer: "}),_fn,(0,O.jsx)("br",{}),(0,O.jsx)("strong",{style:{color:_t.text},children:"UID-Nr.: "}),_uid,(0,O.jsx)("br",{}),(0,O.jsx)("strong",{style:{color:_t.text},children:"Firmenbuchgericht: "}),_court]}),(0,O.jsxs)("p",{children:[(0,O.jsx)("strong",{style:{color:_t.text},children:"Gewerbeberechtigung: "}),_trade,(0,O.jsx)("br",{}),(0,O.jsx)("strong",{style:{color:_t.text},children:"Aufsichtsbeh\u00F6rde: "}),_auth]}),(0,O.jsx)("h3",{className:"text-xl font-bold mt-8 mb-4",style:{color:_t.text},children:"Haftungsausschluss"}),(0,O.jsx)("p",{children:"Die Inhalte dieser Website wurden mit gr\u00F6\u00DFtm\u00F6glicher Sorgfalt erstellt. F\u00FCr die Richtigkeit, Vollst\u00E4ndigkeit und Aktualit\u00E4t der Inhalte \u00FCbernehmen wir jedoch keine Gew\u00E4hr. Als Diensteanbieter sind wir gem\u00E4\u00DF \u00A7 7 Abs. 1 ECG f\u00FCr eigene Inhalte auf diesen Seiten nach den allgemeinen Gesetzen verantwortlich."}),(0,O.jsx)("h3",{className:"text-xl font-bold mt-8 mb-4",style:{color:_t.text},children:"Urheberrecht"}),(0,O.jsx)("p",{children:"Die durch die Seitenbetreiber erstellten Inhalte und Werke auf diesen Seiten unterliegen dem \u00F6sterreichischen Urheberrecht. Die Vervielf\u00E4ltigung, Bearbeitung, Verbreitung und jede Art der Verwertung au\u00DFerhalb der Grenzen des Urheberrechtes bed\u00FCrfen der schriftlichen Zustimmung des jeweiligen Autors bzw. Erstellers."}),_extra?(0,O.jsx)("div",{className:"mt-8",dangerouslySetInnerHTML:{__html:_extra}}):null]})]})})})]})}; """

datenschutz_component = r"""var _DsPage=function(_pp){var _t=_pp.t,_cms=_pp.cms||{},_sp=_pp.setPage,_lg=_cms.legal||{};var _html=_lg.datenschutz_html||"",_ct=_cms.contact||{},_cn=_lg.company_name||"SR-Homes Immobilien GmbH";return(0,O.jsxs)("div",{children:[(0,O.jsx)("section",{className:"pt-32 pb-16 md:pt-40 md:pb-20",style:{background:_t.bgAlt},children:(0,O.jsx)("div",{className:"max-w-[1440px] mx-auto px-6 md:px-12 lg:px-16",children:(0,O.jsx)("h1",{className:"font-display text-4xl md:text-5xl font-bold",style:{color:_t.text},children:"Datenschutzerkl\u00E4rung"})})}),(0,O.jsx)("section",{className:"py-16 md:py-24",style:{background:_t.bg},children:(0,O.jsx)("div",{className:"max-w-[800px] mx-auto px-6 md:px-12",children:_html?(0,O.jsx)("div",{className:"prose prose-lg text-sm leading-relaxed",style:{color:_t.textMuted},dangerouslySetInnerHTML:{__html:_html}}):(0,O.jsxs)("div",{className:"prose prose-lg",style:{color:_t.textMuted},children:[(0,O.jsx)("h2",{className:"text-2xl font-bold mb-6",style:{color:_t.text},children:"1. Datenschutz auf einen Blick"}),(0,O.jsxs)("div",{className:"space-y-4 text-sm leading-relaxed",children:[(0,O.jsx)("h3",{className:"text-xl font-bold mt-6 mb-3",style:{color:_t.text},children:"Allgemeine Hinweise"}),(0,O.jsxs)("p",{children:["Die folgenden Hinweise geben einen einfachen \u00DCberblick dar\u00FCber, was mit Ihren personenbezogenen Daten passiert, wenn Sie diese Website besuchen. Personenbezogene Daten sind alle Daten, mit denen Sie pers\u00F6nlich identifiziert werden k\u00F6nnen."]}),(0,O.jsx)("h3",{className:"text-xl font-bold mt-6 mb-3",style:{color:_t.text},children:"Datenerfassung auf dieser Website"}),(0,O.jsx)("p",{className:"font-semibold",style:{color:_t.text},children:"Wer ist verantwortlich f\u00FCr die Datenerfassung auf dieser Website?"}),(0,O.jsxs)("p",{children:["Die Datenverarbeitung auf dieser Website erfolgt durch den Websitebetreiber: ",_cn,", ",(_ct.address||"").replace(/\n/g,", "),", E-Mail: ",_ct.email||"office@sr-homes.at"]}),(0,O.jsx)("p",{className:"font-semibold",style:{color:_t.text},children:"Wie erfassen wir Ihre Daten?"}),(0,O.jsx)("p",{children:"Ihre Daten werden zum einen dadurch erhoben, dass Sie uns diese mitteilen (z.B. \u00FCber ein Kontaktformular). Andere Daten werden automatisch beim Besuch der Website durch unsere IT-Systeme erfasst (z.B. Browser, Betriebssystem, Uhrzeit des Seitenaufrufs)."}),(0,O.jsx)("h2",{className:"text-2xl font-bold mt-10 mb-6",style:{color:_t.text},children:"2. Hosting"}),(0,O.jsx)("p",{children:"Diese Website wird extern gehostet. Die personenbezogenen Daten, die auf dieser Website erfasst werden, werden auf den Servern des Hosters gespeichert."}),(0,O.jsx)("h2",{className:"text-2xl font-bold mt-10 mb-6",style:{color:_t.text},children:"3. Ihre Rechte"}),(0,O.jsx)("p",{children:"Sie haben jederzeit das Recht, unentgeltlich Auskunft \u00FCber Herkunft, Empf\u00E4nger und Zweck Ihrer gespeicherten personenbezogenen Daten zu erhalten. Sie haben au\u00DFerdem ein Recht, die Berichtigung oder L\u00F6schung dieser Daten zu verlangen. Hierzu sowie zu weiteren Fragen zum Thema Datenschutz k\u00F6nnen Sie sich jederzeit an uns wenden."}),(0,O.jsx)("p",{className:"mt-6 text-xs",style:{color:_t.textMuted},children:"Bitte hinterlegen Sie die vollst\u00E4ndige Datenschutzerkl\u00E4rung im Kundenportal unter Website > Rechtliches."})]})]})})})]})};"""

# Check if already patched
if '_ImpPage' in c:
    print("Components already patched, skipping component injection")
else:
    c = c[:idx] + impressum_component + datenschutz_component + c[idx:]
    patches_applied += 1
    print("1. Injected Impressum & Datenschutz components")

# ────────────────────────────────────────────────────
# 2. Add to page routing dict
# ────────────────────────────────────────────────────

old_dict_end = 'kontakt:(0,O.jsx)(lt,{t:o,cms:c})}'
new_dict_end = 'kontakt:(0,O.jsx)(lt,{t:o,cms:c}),impressum:(0,O.jsx)(_ImpPage,{t:o,cms:c,setPage:u}),datenschutz:(0,O.jsx)(_DsPage,{t:o,cms:c,setPage:u})}'

if 'impressum:(0,O.jsx)(_ImpPage' in c:
    print("2. Page dict already patched, skipping")
else:
    if old_dict_end in c:
        c = c.replace(old_dict_end, new_dict_end, 1)
        patches_applied += 1
        print("2. Added impressum/datenschutz to page dict")
    else:
        print("ERROR: Could not find page dict end marker")
        sys.exit(1)

# ────────────────────────────────────────────────────
# 3. Add footer links for Impressum & Datenschutz
#    Replace the hardcoded legal line in the footer
# ────────────────────────────────────────────────────

old_footer_legal = r"""children:`SR-Homes GmbH | FN 4556571 i | ATU 71268923 | Konzessionierter Immobilientreuh\xe4nder`"""
# Replace hardcoded text with CMS-aware + clickable links
new_footer_legal = r"""children:[n.legal&&n.legal.company_name?n.legal.company_name:`SR-Homes GmbH`,` | `,n.legal&&n.legal.fn_number?n.legal.fn_number:`FN 4556571 i`,` | `,n.legal&&n.legal.uid_number?n.legal.uid_number:`ATU 71268923`]"""

if 'n.legal&&n.legal.company_name' in c:
    print("3. Footer legal line already patched, skipping")
else:
    if old_footer_legal in c:
        c = c.replace(old_footer_legal, new_footer_legal, 1)
        patches_applied += 1
        print("3. Patched footer legal info line")
    else:
        # Try without escape
        old2 = "children:`SR-Homes GmbH | FN 4556571 i | ATU 71268923 | Konzessionierter Immobilientreuh"
        idx2 = c.find(old2)
        if idx2 >= 0:
            # Find the end of this children value
            end2 = c.find('`', idx2 + len("children:`"))
            old_full = c[idx2:end2+1]
            c = c.replace(old_full, new_footer_legal, 1)
            patches_applied += 1
            print("3. Patched footer legal info (alt method)")
        else:
            print("WARNING: Could not find footer legal line")

# ────────────────────────────────────────────────────
# 4. Add Impressum/Datenschutz links row to footer
#    Insert after the legal info line and copyright line
# ────────────────────────────────────────────────────

old_copyright = "children:`2026 SR-Homes. Alle Rechte vorbehalten.`"
# Add a third element with links
new_copyright = """children:[(0,O.jsxs)("span",{children:["\\xA9 2026 SR-Homes. Alle Rechte vorbehalten."]}),(0,O.jsxs)("span",{className:"flex gap-4 ml-4",children:[(0,O.jsx)("button",{onClick:function(){e("impressum")},className:"hover:text-white/50 transition-colors underline",children:"Impressum"}),(0,O.jsx)("button",{onClick:function(){e("datenschutz")},className:"hover:text-white/50 transition-colors underline",children:"Datenschutz"})]})]"""

if 'onClick:function(){e("impressum")}' in c:
    print("4. Footer links already patched, skipping")
else:
    if old_copyright in c:
        c = c.replace(old_copyright, new_copyright, 1)
        patches_applied += 1
        print("4. Added Impressum/Datenschutz links to footer")
    else:
        print("WARNING: Could not find copyright line in footer")

# ────────────────────────────────────────────────────
# 5. Add URL routing for /impressum and /datenschutz
#    Update the path maps in useCallback, popstate, and replaceState
# ────────────────────────────────────────────────────

# 5a. useCallback path map (pushState on nav)
old_nav_map = "detail:`/objekt`}"
new_nav_map = "detail:`/objekt`,impressum:`/impressum`,datenschutz:`/datenschutz`}"
c = c.replace(old_nav_map, new_nav_map)

# 5b. Title map
old_title_detail = "detail:`Immobilie | SR-Homes`}"
new_title_detail = "detail:`Immobilie | SR-Homes`,impressum:`Impressum | SR-Homes`,datenschutz:`Datenschutz | SR-Homes`}"
c = c.replace(old_title_detail, new_title_detail)

# 5c. Initial state from URL (path-to-view)
old_path_init = '"/objekt":"detail"}'
new_path_init = '"/objekt":"detail","/impressum":"impressum","/datenschutz":"datenschutz"}'
c = c.replace(old_path_init, new_path_init)

# 5d. Popstate path-to-view map
old_pop_map = '"/objekt":"detail"};var _h=function(ev)'
new_pop_map = '"/objekt":"detail","/impressum":"impressum","/datenschutz":"datenschutz"};var _h=function(ev)'
c = c.replace(old_pop_map, new_pop_map)

# 5e. replaceState path map
old_rep_map = 'detail:"/objekt"};try{history.replaceState'
new_rep_map = 'detail:"/objekt",impressum:"/impressum",datenschutz:"/datenschutz"};try{history.replaceState'
c = c.replace(old_rep_map, new_rep_map)

patches_applied += 1
print("5. Updated all URL routing maps")

# ────────────────────────────────────────────────────
# Write result
# ────────────────────────────────────────────────────
with open(BUNDLE, 'w') as f:
    f.write(c)

print(f"\nDone! {patches_applied} patches applied.")
