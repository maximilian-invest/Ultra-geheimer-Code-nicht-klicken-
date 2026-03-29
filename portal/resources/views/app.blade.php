<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
        <meta name="description" content="SR-Homes Immobilien Kundenportal - Ihr Partner für Immobilien in Salzburg">
        <meta name="theme-color" content="#ee7606">

        <title inertia>{{ config('app.name', 'SR-Homes') }}</title>

        <!-- Favicon -->
        <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
        <link rel="icon" type="image/svg+xml" href="/assets/logo-icon-orange.svg">
        <link rel="apple-touch-icon" href="/assets/logo-icon-orange.svg">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&family=manrope:600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
        {{-- Runtime patch: inject Rechtliches tab into WebsiteTab --}}
        <script>
        (function(){
            var LEGAL_SECTION = {key:'legal',label:'Rechtliches'};
            var MAX_ATTEMPTS = 60;
            var attempt = 0;
            function injectTab(){
                attempt++;
                // Find the WebsiteTab pill navigation
                var navs = document.querySelectorAll('button');
                var brandingBtn = null;
                navs.forEach(function(b){
                    if(b.textContent.trim()==='Branding') brandingBtn = b;
                });
                if(!brandingBtn){
                    if(attempt < MAX_ATTEMPTS) setTimeout(injectTab, 500);
                    return;
                }
                // Check if already injected
                var exists = false;
                navs.forEach(function(b){ if(b.textContent.trim()==='Rechtliches') exists = true; });
                if(exists) return;

                // Clone the Branding button style and create Rechtliches button
                var btn = brandingBtn.cloneNode(true);
                btn.textContent = 'Rechtliches';
                // Add a shield icon SVG before text
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Rechtliches';
                btn.className = brandingBtn.className;
                // Make it look inactive
                btn.classList.remove('bg-[var(--foreground)]','text-[var(--background)]');
                btn.classList.add('text-[var(--muted-foreground)]');
                brandingBtn.parentNode.insertBefore(btn, brandingBtn.nextSibling);

                btn.addEventListener('click', function(){
                    showLegalPanel();
                    // Update button states
                    brandingBtn.parentNode.querySelectorAll('button').forEach(function(b){
                        b.classList.remove('bg-[var(--foreground)]','text-[var(--background)]');
                        b.classList.add('text-[var(--muted-foreground)]');
                    });
                    btn.classList.add('bg-[var(--foreground)]','text-[var(--background)]');
                    btn.classList.remove('text-[var(--muted-foreground)]');
                });
            }

            function showLegalPanel(){
                // Find the content area (max-w-3xl)
                var content = document.querySelector('.max-w-3xl.mx-auto');
                if(!content) return;

                // Get API base from the page
                var apiMatch = document.cookie.match(/XSRF/)||null;
                var apiBase = '';
                var scripts = document.querySelectorAll('script');
                // Find API key from existing Vue app
                var apiUrl = '';
                try{
                    var el = document.querySelector('[data-page]');
                    if(el){
                        var page = JSON.parse(el.getAttribute('data-page'));
                        apiUrl = '/api/admin_api.php?key=' + (page.props.apiKey||'');
                    }
                }catch(e){}

                content.innerHTML = buildLegalHTML(apiUrl);
                loadLegalData(apiUrl);
            }

            function buildLegalHTML(apiUrl){
                return '<div class="space-y-5" id="sr-legal-panel" data-api="'+apiUrl+'">'
                +'<div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">'
                +'<h2 class="text-base font-bold text-[var(--foreground)] mb-5 flex items-center gap-2">'
                +'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent)"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>'
                +' Impressum</h2>'
                +'<p class="text-xs text-[var(--muted-foreground)] mb-3">Wird auf der Website unter /impressum angezeigt. HTML erlaubt.</p>'
                +'<div class="space-y-4">'
                +legalField('company_name','Firmenname','SR-Homes Immobilien GmbH')
                +'<div class="grid grid-cols-2 gap-4">'
                +legalField('fn_number','FN (Firmenbuchnummer)','FN 4556571 i')
                +legalField('uid_number','UID-Nr.','ATU 71268923')
                +'</div>'
                +'<div class="grid grid-cols-2 gap-4">'
                +legalField('ceo_name','Geschäftsführer','')
                +legalField('court','Firmenbuchgericht','Landesgericht Salzburg')
                +'</div>'
                +legalField('trade_license','Gewerbe / Berechtigung','Konzessionierter Immobilientreuhänder')
                +legalField('authority','Aufsichtsbehörde','Magistrat der Stadt Salzburg')
                +legalArea('impressum_extra','Zusätzlicher Impressum-Text (HTML)',6)
                +'</div>'
                +'<div class="mt-6 flex justify-end">'
                +'<button onclick="saveLegalSection()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] transition-all">'
                +'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>'
                +' Speichern</button></div></div>'

                +'<div class="p-6 rounded-2xl bg-[var(--card)] border border-[var(--border)]">'
                +'<h2 class="text-base font-bold text-[var(--foreground)] mb-5 flex items-center gap-2">'
                +'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent)"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>'
                +' Datenschutzerklärung</h2>'
                +'<p class="text-xs text-[var(--muted-foreground)] mb-3">Wird auf der Website unter /datenschutz angezeigt. HTML erlaubt.</p>'
                +legalArea('datenschutz_html','Datenschutzerklärung (HTML)',20)
                +'<div class="mt-6 flex justify-end">'
                +'<button onclick="saveLegalSection()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-zinc-900 text-white hover:bg-zinc-800 active:scale-[0.98] transition-all">'
                +'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>'
                +' Speichern</button></div></div></div>';
            }

            function legalField(key,label,ph){
                return '<div><label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">'+label+'</label>'
                +'<input type="text" id="legal-'+key+'" placeholder="'+ph+'" '
                +'class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] focus:border-[var(--accent)] outline-none transition-all" /></div>';
            }
            function legalArea(key,label,rows){
                return '<div><label class="text-xs font-semibold text-[var(--muted-foreground)] mb-1.5 block">'+label+'</label>'
                +'<textarea id="legal-'+key+'" rows="'+rows+'" '
                +'class="w-full px-4 py-2.5 rounded-xl text-sm bg-[var(--background)] border border-[var(--border)] text-[var(--foreground)] resize-y focus:border-[var(--accent)] outline-none transition-all font-mono"></textarea></div>';
            }

            function loadLegalData(apiUrl){
                if(!apiUrl) return;
                fetch(apiUrl+'&action=website_content_list&section=legal')
                .then(function(r){return r.json()})
                .then(function(d){
                    if(!d.success) return;
                    (d.items||[]).forEach(function(item){
                        var el = document.getElementById('legal-'+item.content_key);
                        if(el) el.value = item.content_value||'';
                    });
                }).catch(function(){});
            }

            window.saveLegalSection = function(){
                var panel = document.getElementById('sr-legal-panel');
                if(!panel) return;
                var apiUrl = panel.getAttribute('data-api');
                var fields = ['company_name','fn_number','uid_number','ceo_name','court','trade_license','authority','impressum_extra','datenschutz_html'];
                var saved = 0;
                var total = fields.length;
                fields.forEach(function(key){
                    var el = document.getElementById('legal-'+key);
                    if(!el) { total--; return; }
                    var val = el.value||'';
                    fetch(apiUrl+'&action=website_content_save',{
                        method:'POST',
                        headers:{'Content-Type':'application/json'},
                        body:JSON.stringify({section:'legal',content_key:key,content_type:'text',content_value:val})
                    }).then(function(r){return r.json()}).then(function(d){
                        saved++;
                        if(saved>=total){
                            alert('Gespeichert! ('+saved+' Einträge)');
                            // Clear website cache
                            fetch(apiUrl+'&action=website_clear_cache',{method:'POST'}).catch(function(){});
                        }
                    }).catch(function(){});
                });
            };

            // Start observing for when WebsiteTab is active
            var observer = new MutationObserver(function(){
                injectTab();
            });
            observer.observe(document.body, {childList:true, subtree:true});
            // Also try immediately and after short delays
            setTimeout(injectTab, 1000);
            setTimeout(injectTab, 3000);
        })();
        </script>
    </body>
</html>
