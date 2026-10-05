const CACHE='tsb-os-v19-operations-v2-round2';
const ASSETS=['/','/app.css','/data.js','/app.js','/manifest.webmanifest','https://unpkg.com/react@18.3.1/umd/react.production.min.js','https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js'];
self.addEventListener('install',e=>{self.skipWaiting();e.waitUntil(caches.open(CACHE).then(c=>Promise.allSettled(ASSETS.map(x=>c.add(x)))))});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});
self.addEventListener('fetch',e=>{
 if(e.request.method!=='GET'||new URL(e.request.url).pathname.startsWith('/api/'))return;
 e.respondWith(fetch(e.request).then(res=>{const copy=res.clone();caches.open(CACHE).then(c=>c.put(e.request,copy));return res}).catch(()=>caches.match(e.request).then(r=>r||caches.match('/'))))
});