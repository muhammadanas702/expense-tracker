"""Text contrast check (WCAG 4.5:1, 3:1 for large text) in light or dark mode. Samples the real background behind every text and compares it with the text colour.
Usage (server and test DB running, run from the repo root): python3 tests/contrast_check.py dark|light 390 "/shared/index.php" "/shared/group.php?id={gid}" ...
Texts inside closed drop-downs can show up as false positives (ratio about 1.0): ignore those."""
import subprocess, sys, os, re, io, statistics
from playwright.sync_api import sync_playwright
from PIL import Image
B='http://127.0.0.1:8080'
mode=sys.argv[1]; w=int(sys.argv[2]); paths=sys.argv[3:]
def sql(q): return subprocess.run(['mysql','--socket=/run/mysqld/mysqld.sock','-uroot','expenseflow_test','-N','-e',q],capture_output=True,text=True).stdout.strip()
h=subprocess.run(['php','-r','echo password_hash("Test#12345",PASSWORD_DEFAULT);'],capture_output=True,text=True).stdout
email='shot@audit.local'
sql(f"DELETE FROM users WHERE email='{email}'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('Muhammad Anas','{email}','{h}','user',0)")
uid=sql(f"SELECT id FROM users WHERE email='{email}'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
gid=subprocess.run(['php','tests/ui_seed_shared.php',uid],capture_output=True,text=True).stdout.strip()
paths=[p.replace('{gid}',gid) for p in paths]
def lum(c):
    def f(v):
        v/=255; return v/12.92 if v<=0.03928 else ((v+0.055)/1.055)**2.4
    return 0.2126*f(c[0])+0.7152*f(c[1])+0.0722*f(c[2])
def ratio(a,b):
    la,lb=lum(a),lum(b); 
    if la<lb: la,lb=lb,la
    return (la+0.05)/(lb+0.05)
JS="""()=>{const out=[];const seen=new Set();
document.querySelectorAll('body *').forEach(el=>{
 if(['SCRIPT','STYLE','NOSCRIPT','SVG','PATH'].includes(el.tagName.toUpperCase()))return;
 let t='';el.childNodes.forEach(n=>{if(n.nodeType===3)t+=n.textContent});t=t.trim();if(!t)return;
 const r=el.getBoundingClientRect();if(r.width<4||r.height<4)return;
 const cs=getComputedStyle(el);if(cs.visibility==='hidden'||cs.display==='none')return;
 let op=1,p=el;while(p&&p!==document.body){op*=parseFloat(getComputedStyle(p).opacity);p=p.parentElement}
 if(op<0.05)return;
 const m=cs.color.match(/[\\d.]+/g).map(Number);
 const fs=parseFloat(cs.fontSize);
 out.push({t:t.slice(0,40),c:m,op:op,x:r.left+scrollX,y:r.top+scrollY,w:r.width,h:r.height,fs:fs,fw:cs.fontWeight});});
return out}"""
with sync_playwright() as p:
    br=p.chromium.launch()
    ctx=br.new_context(viewport={'width':w,'height':820},service_workers='block',is_mobile=w<600)
    if mode=='dark': ctx.add_cookies([{'name':'ef_theme','value':'dark','url':B}])
    pg=ctx.new_page()
    pg.goto(B+'/auth/login.php'); pg.fill('input[name=email]',email); pg.fill('input[name=password]','Test#12345'); pg.click('button[type=submit]'); pg.wait_for_load_state()
    for path in paths:
        pg.goto(B+path); pg.wait_for_timeout(1000)
        pg.add_style_tag(content='.bn,.fabf,header.top{position:absolute!important}')
        items=pg.evaluate(JS)
        pg.add_style_tag(content='*{color:transparent!important;-webkit-text-fill-color:transparent!important;text-shadow:none!important}')
        img=Image.open(io.BytesIO(pg.screenshot(full_page=True))).convert('RGB')
        bad=[]
        for it in items:
            x0,y0=int(it['x']),int(it['y']); x1,y1=int(it['x']+it['w']),int(it['y']+it['h'])
            x1=min(x1,img.width); y1=min(y1,img.height)
            if x1<=x0 or y1<=y0 or x0<0: continue
            reg=img.crop((x0,y0,x1,y1)); px=list(reg.get_flattened_data() if hasattr(reg,'get_flattened_data') else reg.getdata())
            bg=tuple(statistics.median(c[i] for c in px) for i in range(3))
            fg=it['c'][:3]; a=(it['c'][3] if len(it['c'])>3 else 1)*it['op']
            fgm=tuple(fg[i]*a+bg[i]*(1-a) for i in range(3))
            r=ratio(fgm,bg); need=3.0 if (it['fs']>=24 or (it['fs']>=18.6 and int(it['fw'])>=700)) else 4.5
            if r<need: bad.append((round(r,2),it['t'],[round(v) for v in fgm],[round(v) for v in bg]))
        print(f"{path}  [{mode} {w}px]  {len(items)} texts, {len(bad)} low-contrast")
        seen=set()
        for b in sorted(bad)[:14]:
            if b[1] in seen: continue
            seen.add(b[1]); print('   ',b)
    br.close()
sql("DELETE FROM users WHERE email LIKE 'seed%@shot.local'"); sql(f"DELETE FROM users WHERE email='{email}'")
