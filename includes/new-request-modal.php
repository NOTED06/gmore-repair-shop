<?php

?>

<div class="nr-overlay" id="nrOverlay">
  <div class="nr-modal">
    
    <div class="nr-header">
      <div class="nr-header-left">
        <div class="nr-header-icon"><i data-lucide="plus-circle"></i></div>
        <div>
          <div class="nr-header-title">New Repair Request</div>
          <div class="nr-header-sub">Describe your device and we'll take it from here.</div>
        </div>
      </div>
      <button class="nr-close" id="nrCloseBtn"><i data-lucide="x"></i></button>
    </div>

    
    <form class="nr-body" id="nrForm" enctype="multipart/form-data" novalidate>
      <?php echo csrfField(); ?>

      
      <div class="nr-alert nr-alert-err" id="nrAlertErr" style="display:none">
        <i data-lucide="alert-circle"></i>
        <span id="nrAlertErrMsg"></span>
      </div>

      
      <div class="nr-field">
        <label class="nr-label">Appliance Type <span class="nr-req">*</span></label>
        <div style="position:relative">
          <input type="text" name="appliance_type" id="nrApplianceType" class="nr-input" placeholder="e.g. Refrigerator, TV, Washing Machine" autocomplete="off" required>
          <div class="nr-suggestions" id="nrSuggestions"></div>
        </div>
      </div>

      
      <div class="nr-row3">
        <div class="nr-field">
          <label class="nr-label">Brand</label>
          <input type="text" name="brand" id="nrBrand" class="nr-input" placeholder="e.g. Samsung">
        </div>
        <div class="nr-field">
          <label class="nr-label">Model</label>
          <input type="text" name="model" id="nrModel" class="nr-input" placeholder="e.g. RT38K5590S8">
        </div>
        <div class="nr-field">
          <label class="nr-label">Serial No.</label>
          <input type="text" name="serial_number" class="nr-input" placeholder="Optional">
        </div>
      </div>

      
      <div class="nr-field">
        <label class="nr-label">Problem Description <span class="nr-req">*</span></label>
        <textarea name="problem_description" id="nrProbDesc" class="nr-input" rows="4" placeholder="Describe the issue in detail…" required></textarea>
        <span class="nr-hint">The more detail you provide, the faster we can diagnose.</span>
      </div>

      
      <div class="nr-field">
        <label class="nr-label">Photo (optional)</label>
        <div class="nr-upload" id="nrUploadZone">
          <input type="file" name="appliance_image" id="nrFileInput" accept="image/*">
          <i data-lucide="image-plus"></i>
          <span>Click or drag & drop</span>
          <small>JPG, PNG, GIF, WebP up to 5MB</small>
        </div>
        <div class="nr-preview" id="nrPreview" style="display:none">
          <img id="nrPreviewImg" src="" alt="Preview">
          <button type="button" class="nr-preview-rm" id="nrRemoveImg"><i data-lucide="x"></i></button>
        </div>
      </div>

      
      <button type="submit" class="nr-submit" id="nrSubmitBtn">
        <i data-lucide="send"></i> Submit Repair Request
      </button>
    </form>
  </div>
</div>

<style>

.nr-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);z-index:950;align-items:center;justify-content:center}
.nr-overlay.open{display:flex}

.nr-modal{width:560px;max-height:90vh;background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(30,58,138,.25);display:flex;flex-direction:column;overflow:hidden;animation:nr-in .25s ease}
@keyframes nr-in{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}

.nr-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;background:var(--navbar-gradient);color:#fff;flex-shrink:0}
.nr-header-left{display:flex;align-items:center;gap:12px}
.nr-header-icon{width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
.nr-header-icon svg{width:20px;height:20px;color:#fff}
.nr-header-title{font-family:'Syne',sans-serif;font-weight:700;font-size:17px}
.nr-header-sub{font-size:12px;opacity:.8;margin-top:1px}
.nr-close{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.15);border:none;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}
.nr-close:hover{background:rgba(255,255,255,.3)}
.nr-close svg{width:18px;height:18px}

.nr-body{padding:20px 24px;overflow-y:auto;display:flex;flex-direction:column;gap:14px;flex:1}
.nr-body::-webkit-scrollbar{width:4px}
.nr-body::-webkit-scrollbar-thumb{background:var(--gold-mid);border-radius:2px}

.nr-field{display:flex;flex-direction:column;gap:4px}
.nr-label{font-size:12px;font-weight:600;color:#444}
.nr-req{color:#EF4444}
.nr-input{width:100%;border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:13px;font-family:inherit;outline:none;box-sizing:border-box;transition:border-color .15s,box-shadow .15s}
.nr-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.12)}
.nr-hint{font-size:11px;color:#999}
.nr-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}

.nr-suggestions{display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:8px;max-height:160px;overflow-y:auto;z-index:10;box-shadow:0 8px 24px rgba(0,0,0,.1)}
.nr-suggestions.open{display:block}
.nr-suggestions .s-item{padding:8px 12px;cursor:pointer;font-size:13px;transition:background .1s}
.nr-suggestions .s-item:hover{background:var(--gold-light)}
.nr-suggestions .s-item .s-desc{font-size:11px;color:#999}
.nr-suggestions .s-none{padding:10px 12px;font-size:12px;color:#aaa;text-align:center}

.nr-upload{position:relative;border:2px dashed var(--border);border-radius:10px;padding:20px;text-align:center;cursor:pointer;transition:border-color .15s,background .15s;display:flex;flex-direction:column;align-items:center;gap:4px;color:#999}
.nr-upload:hover{border-color:var(--gold);background:var(--gold-light)}
.nr-upload input{position:absolute;inset:0;opacity:0;cursor:pointer}
.nr-upload svg{width:24px;height:24px;color:var(--gold)}
.nr-upload span{font-size:13px}
.nr-upload small{font-size:11px;color:#bbb}
.nr-preview{position:relative;display:inline-block;border-radius:8px;overflow:hidden;max-width:200px}
.nr-preview img{width:100%;display:block;border-radius:8px}
.nr-preview-rm{position:absolute;top:4px;right:4px;width:24px;height:24px;border-radius:50%;background:rgba(0,0,0,.6);border:none;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center}
.nr-preview-rm svg{width:14px;height:14px}

.nr-alert{padding:10px 14px;border-radius:8px;font-size:13px;display:flex;align-items:center;gap:8px}
.nr-alert svg{width:16px;height:16px;flex-shrink:0}
.nr-alert-err{background:#FEF2F2;color:#DC2626;border:1px solid #FECACA}

.nr-submit{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;border-radius:10px;background:var(--primary);color:#fff;border:none;cursor:pointer;font-size:14px;font-weight:600;font-family:inherit;transition:background .15s}
.nr-submit:hover{background:var(--primary-dark)}
.nr-submit:disabled{opacity:.6;cursor:not-allowed}
.nr-submit svg{width:16px;height:16px}

@media(max-width:640px){.nr-modal{width:calc(100vw - 20px);max-height:95vh}.nr-row3{grid-template-columns:1fr}}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const SITE     = '<?= SITE_URL ?>';
    const overlay  = document.getElementById('nrOverlay');
    const closeBtn = document.getElementById('nrCloseBtn');
    const form     = document.getElementById('nrForm');
    if (!overlay || !form) return;

    
    window._openNewRequest = () => { overlay.classList.add('open'); if (typeof lucide !== 'undefined') lucide.createIcons(); };
    closeBtn.addEventListener('click', () => overlay.classList.remove('open'));
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.classList.remove('open'); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') overlay.classList.remove('open'); });

    
    const aInput = document.getElementById('nrApplianceType');
    const sBox   = document.getElementById('nrSuggestions');
    let aTimer;
    if (aInput && sBox) {
        aInput.addEventListener('input', () => {
            clearTimeout(aTimer);
            const q = aInput.value.trim();
            if (q.length < 2) { sBox.classList.remove('open'); return; }
            aTimer = setTimeout(() => {
                fetch(`${SITE}/api/search-appliances.php?q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(data => {
                        sBox.innerHTML = '';
                        if (!data.length) { sBox.innerHTML = '<div class="s-none">No matches</div>'; }
                        else data.forEach(item => {
                            const el = document.createElement('div');
                            el.className = 's-item';
                            
                            el.innerHTML = `
                                <div>${item.appliance_type}</div>
                                <div class="s-desc">${item.brand} ${item.model} ${item.description ? ' - ' + item.description : ''}</div>
                            `;
                            el.addEventListener('mousedown', () => { 
                                aInput.value = item.appliance_type;
                                
                                const bIn = document.getElementById('nrBrand');
                                const mIn = document.getElementById('nrModel');
                                if (bIn) bIn.value = item.brand;
                                if (mIn) mIn.value = item.model;
                                sBox.classList.remove('open'); 
                            });
                            sBox.appendChild(el);
                        });
                        sBox.classList.add('open');
                    }).catch(() => sBox.classList.remove('open'));
            }, 280);
        });
        document.addEventListener('click', e => { if (!aInput.contains(e.target)) sBox.classList.remove('open'); });
    }

    
    const fileInput = document.getElementById('nrFileInput');
    const preview   = document.getElementById('nrPreview');
    const prevImg   = document.getElementById('nrPreviewImg');
    const rmBtn     = document.getElementById('nrRemoveImg');
    if (fileInput) {
        fileInput.addEventListener('change', () => {
            const f = fileInput.files[0];
            if (f) { prevImg.src = URL.createObjectURL(f); preview.style.display = ''; document.getElementById('nrUploadZone').style.display = 'none'; }
        });
        rmBtn?.addEventListener('click', () => { fileInput.value = ''; preview.style.display = 'none'; document.getElementById('nrUploadZone').style.display = ''; });
    }

    
    form.addEventListener('submit', e => {
        e.preventDefault();
        const errBox = document.getElementById('nrAlertErr');
        const errMsg = document.getElementById('nrAlertErrMsg');
        errBox.style.display = 'none';

        const fd = new FormData(form);

        const desc = fd.get('problem_description')?.trim();
        if (!fd.get('appliance_type')?.trim()) { errMsg.textContent = 'Appliance type is required.'; errBox.style.display = ''; return; }
        if (!desc) { errMsg.textContent = 'Problem description is required.'; errBox.style.display = ''; return; }

        const btn = document.getElementById('nrSubmitBtn');
        btn.disabled = true; btn.innerHTML = '<i data-lucide="loader-2"></i> Submitting…';
        if (typeof lucide !== 'undefined') lucide.createIcons();

        fetch(`${SITE}/customer/new-request.php`, {
            method: 'POST',
            body: fd
        })
        .then(r => {
            if (r.redirected) {
                
                window.location.href = r.url;
                return;
            }
            return r.text();
        })
        .then(html => {
            if (!html) return;
            
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const alertEl = doc.querySelector('.alert-danger');
            if (alertEl) {
                errMsg.textContent = alertEl.textContent.trim();
                errBox.style.display = '';
                btn.disabled = false; btn.innerHTML = '<i data-lucide="send"></i> Submit Repair Request';
                if (typeof lucide !== 'undefined') lucide.createIcons();
            } else {
                
                window.location.href = `${SITE}/customer/repairs.php`;
            }
        })
        .catch(() => {
            errMsg.textContent = 'Network error. Please try again.';
            errBox.style.display = '';
            btn.disabled = false; btn.innerHTML = '<i data-lucide="send"></i> Submit Repair Request';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    });
});
</script>
