<?php

?>

<div class="pv-overlay" id="pvOverlay">
    <div class="pv-modal">
        
        <button class="pv-close" id="pvCloseBtn" aria-label="Close modal"><i data-lucide="x"></i></button>
        
        <div class="pv-layout">
            
            <div class="pv-image-area" id="pvImageArea">
                <div class="pv-img-placeholder" id="pvImgPlaceholder">
                    <i data-lucide="package" id="pvImgIcon"></i>
                </div>
                <img src="" alt="Product" id="pvImage" class="pv-image" style="display:none">
                
                <span class="pv-badge-cat badge badge-teal" id="pvCategoryBadge">Category</span>
                <span class="pv-badge-stock" id="pvStockBadge">0 left</span>
            </div>
            
            
            <div class="pv-details-area">
                <div class="pv-header">
                    <h2 class="pv-title" id="pvTitle">Product Name</h2>
                    <div class="pv-price" id="pvPrice">₱0.00</div>
                    <div class="pv-status" id="pvStatusWrap">
                        <span class="badge badge-emerald" id="pvStatus"><i data-lucide="check"></i> In Stock</span>
                    </div>
                </div>
                
                <div class="pv-divider"></div>
                
                <div class="pv-desc-wrap">
                    <h3 class="pv-subtitle">Product Description</h3>
                    <p class="pv-desc" id="pvDesc">No description available.</p>
                </div>
                
                <div class="pv-actions">
                    <button class="btn-primary" id="pvAddToCart" style="flex:1;justify-content:center">
                        <i data-lucide="shopping-cart"></i> Add to Cart
                    </button>
                    <button class="btn-ghost" id="pvAddToList" title="Add to Wishlist">
                        <i data-lucide="heart"></i>
                    </button>
                </div>
                
                <div class="pv-footer-note">
                    <i data-lucide="shield-check"></i> Standard 30-day warranty applies.
                </div>
            </div>
        </div>
    </div>
</div>

<style>

.pv-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); backdrop-filter: blur(5px); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.pv-overlay.open { display: flex; animation: pv-fade 0.2s ease; }
@keyframes pv-fade { from { opacity: 0; } to { opacity: 1; } }

.pv-modal { position: relative; width: 100%; max-width: 800px; background: #fff; border-radius: 20px; box-shadow: 0 24px 60px rgba(30,58,138,0.25); overflow: hidden; animation: pv-in 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes pv-in { from { opacity: 0; transform: translateY(20px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }

.pv-close { position: absolute; top: 16px; right: 16px; width: 36px; height: 36px; border-radius: 50%; background: #F1F5F9; border: none; color: #64748B; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 10; transition: all 0.2s; }
.pv-close:hover { background: #E2E8F0; color: #0F172A; transform: scale(1.05); }
.pv-close svg { width: 18px; height: 18px; }

.pv-layout { display: flex; flex-direction: row; min-height: 400px; max-height: 85vh; }

.pv-image-area { position: relative; flex: 1; min-width: 300px; background: linear-gradient(135deg, var(--bg-main) 0%, var(--bg-white) 100%); display: flex; align-items: center; justify-content: center; padding: 40px; border-right: 1px solid var(--border); overflow: hidden; }
.pv-image-area.cat-electronics { background: linear-gradient(135deg, #1E40AF 0%, #3B82F6 100%); }
.pv-image-area.cat-computer-parts { background: linear-gradient(135deg, #5B21B6 0%, #8B5CF6 100%); }
.pv-image-area.cat-mobile { background: linear-gradient(135deg, #374151 0%, #6B7280 100%); }
.pv-image-area.cat-home-appliances { background: linear-gradient(135deg, #B45309 0%, #F59E0B 100%); }
.pv-image-area.cat-tools { background: linear-gradient(135deg, #065F46 0%, #10B981 100%); }
.pv-image-area.cat-gadgets { background: linear-gradient(135deg, #B45309 0%, #FBBF24 100%); }

.pv-img-placeholder { width: 120px; height: 120px; border-radius: 50%; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px); }
.pv-img-placeholder svg { width: 48px; height: 48px; color: rgba(255,255,255,0.9); }

.pv-image { max-width: 100%; max-height: 100%; object-fit: contain; z-index: 2; filter: drop-shadow(0 10px 20px rgba(0,0,0,0.15)); }

.pv-badge-cat { position: absolute; top: 20px; left: 20px; z-index: 3; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.pv-badge-stock { position: absolute; bottom: 20px; left: 20px; z-index: 3; padding: 4px 10px; border-radius: 6px; background: rgba(0,0,0,0.6); color: #fff; font-size: 11px; font-weight: 600; backdrop-filter: blur(4px); }

.pv-details-area { flex: 1; display: flex; flex-direction: column; padding: 40px; background: #FAFAF5; overflow-y: auto; }
.pv-details-area::-webkit-scrollbar { width: 6px; }
.pv-details-area::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 3px; }

.pv-header { margin-bottom: 24px; padding-right: 20px; }
.pv-title { font-family: 'Syne', sans-serif; font-weight: 800; font-size: 26px; color: #0F172A; margin: 0 0 8px; line-height: 1.2; }
.pv-price { font-size: 22px; font-weight: 700; color: var(--primary); margin: 0 0 12px; }
.pv-status { display: flex; align-items: center; }

.pv-divider { height: 1px; background: var(--border); margin: 0 0 24px; }

.pv-desc-wrap { flex: 1; margin-bottom: 30px; }
.pv-subtitle { font-size: 13px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 10px; }
.pv-desc { font-size: 14px; color: #475569; line-height: 1.6; margin: 0; white-space: pre-wrap; }

.pv-actions { display: flex; gap: 12px; margin-bottom: 20px; }
.pv-footer-note { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #94A3B8; }
.pv-footer-note svg { width: 14px; height: 14px; color: #CBD5E1; }

@media (max-width: 768px) {
    .pv-layout { flex-direction: column; }
    .pv-image-area { min-height: 250px; flex: 0 0 auto; padding: 30px; }
    .pv-details-area { padding: 24px; flex: 1; }
    .pv-title { font-size: 22px; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const overlay = document.getElementById('pvOverlay');
    const closeBtn = document.getElementById('pvCloseBtn');
    
    const titleEl = document.getElementById('pvTitle');
    const descEl = document.getElementById('pvDesc');
    const priceEl = document.getElementById('pvPrice');
    const catBadge = document.getElementById('pvCategoryBadge');
    const stockBadge = document.getElementById('pvStockBadge');
    const statusWrap = document.getElementById('pvStatusWrap');
    
    const imageArea = document.getElementById('pvImageArea');
    const imgEl = document.getElementById('pvImage');
    const placeholderEl = document.getElementById('pvImgPlaceholder');
    const iconEl = document.getElementById('pvImgIcon');
    
    
    function openProductModal(card) {
        const rawName = card.dataset.rawName || card.dataset.name;
        const rawDesc = card.dataset.rawDesc || card.dataset.desc || 'No description available.';
        const rawCat = card.dataset.rawCategory || card.dataset.category || 'Uncategorized';
        const formattedPrice = card.dataset.formattedPrice || card.dataset.price;
        const stock = parseInt(card.dataset.stock || 0);
        const image = card.dataset.image;
        const icon = card.dataset.icon || 'package';
        const slug = card.dataset.slug || '';
        const status = card.dataset.status;
        
        
        titleEl.textContent = rawName;
        descEl.textContent = rawDesc;
        priceEl.textContent = formattedPrice;
        catBadge.textContent = rawCat;
        stockBadge.textContent = stock + ' left';
        
        
        imageArea.className = 'pv-image-area ' + slug;
        if (image && image.trim() !== '') {
            imgEl.src = image;
            imgEl.style.display = 'block';
            placeholderEl.style.display = 'none';
        } else {
            imgEl.src = '';
            imgEl.style.display = 'none';
            placeholderEl.style.display = 'flex';
            iconEl.setAttribute('data-lucide', icon);
            if (typeof lucide !== 'undefined') lucide.createIcons({ name: icon });
        }
        
        
        if (status === 'available' && stock > 0) {
            statusWrap.innerHTML = '<span class="badge badge-emerald"><i data-lucide="check"></i> In Stock</span>';
            catBadge.className = 'pv-badge-cat badge badge-teal';
            document.getElementById('pvAddToCart').disabled = false;
        } else {
            statusWrap.innerHTML = '<span class="badge badge-rose"><i data-lucide="x"></i> Sold Out</span>';
            catBadge.className = 'pv-badge-cat badge badge-rose';
            stockBadge.textContent = '0 left';
            document.getElementById('pvAddToCart').disabled = true;
            
            imageArea.style.filter = 'grayscale(0.7)';
        }
        
        
        if (status === 'available') {
            imageArea.style.filter = 'none';
        }
        
        if (typeof lucide !== 'undefined') lucide.createIcons();
        overlay.classList.add('open');
    }
    
    
    document.addEventListener('click', e => {
        const btn = e.target.closest('.btn-view-product');
        if (btn) {
            e.preventDefault();
            const card = btn.closest('.product-card');
            if (card) openProductModal(card);
        }
    });
    
    
    closeBtn.addEventListener('click', () => overlay.classList.remove('open'));
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.classList.remove('open'); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') overlay.classList.remove('open'); });
});
</script>
