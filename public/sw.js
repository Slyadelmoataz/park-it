const C='bayline-v1',S=['./','index.html','manifest.webmanifest','icon.svg'];
self.addEventListener('install',e=>e.waitUntil(caches.open(C).then(c=>c.addAll(S))));
self.addEventListener('fetch',e=>{const u=new URL(e.request.url);
 if(e.request.method!=='GET'||u.pathname.includes('/api/'))return; // API is never cached
 e.respondWith(fetch(e.request).catch(()=>caches.match(e.request)))});
