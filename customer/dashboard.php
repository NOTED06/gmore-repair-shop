<?php

require_once '../config/config.php';
requireRole('Customer');

$db = getDB();

$rawCategories = $db->query("
    SELECT DISTINCT pc.* 
    FROM product_categories pc
    INNER JOIN products p ON pc.category_id = p.category_id
    ORDER BY pc.category_name
")->fetchAll();
$categories = [];
$seenNames = [];
foreach ($rawCategories as $cat) {
    $nameClean = trim(strtolower($cat['category_name'] ?? ''));
    if ($nameClean !== '' && !in_array($nameClean, $seenNames)) {
        $seenNames[] = $nameClean;
        $categories[] = $cat;
    }
}

$stmt = $db->prepare("
    SELECT p.*, pc.category_name
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.category_id
    ORDER BY p.created_at DESC
");
$stmt->execute();
$allProducts = $stmt->fetchAll();

$products     = [];   
$soldOut      = [];   
$underRepair  = [];   

foreach ($allProducts as $p) {
    if ($p['status'] === 'Sold Out' || (int)$p['quantity'] === 0) {
        $soldOut[] = $p;
    } else {
        $products[] = $p;
    }
}

$availableCount = count($products);
$soldOutCount   = count($soldOut);

$newArrivals = $db->query("
    SELECT p.*, pc.category_name
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.category_id
    ORDER BY p.created_at DESC
    LIMIT 10
")->fetchAll();

$pageTitle    = 'Dashboard';
$activePage   = 'dashboard';
$notifCount   = getUnreadNotificationCount($_SESSION['user_id']);
$userName     = sanitize($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer');
$nameParts    = explode(' ', $userName);
$userInitials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

function catSlug($name) {
    $map = [
        'electronics'     => 'cat-electronics',
        'computer'        => 'cat-computer-parts',
        'home appliance'  => 'cat-home-appliances',
        'home appliances' => 'cat-home-appliances',
        'tools'           => 'cat-tools',
        'mobile'          => 'cat-mobile',
        'gadget'          => 'cat-gadgets',
    ];
    $lower = strtolower($name ?? '');
    foreach ($map as $k => $v) {
        if (strpos($lower, $k) !== false) return $v;
    }
    return 'cat-default';
}

function catIcon($name) {
    $lower = strtolower($name ?? '');
    if (strpos($lower, 'electronic') !== false)  return 'zap';
    if (strpos($lower, 'computer') !== false)    return 'monitor';
    if (strpos($lower, 'mobile') !== false)      return 'smartphone';
    if (strpos($lower, 'home') !== false)        return 'home';
    if (strpos($lower, 'tool') !== false)        return 'wrench';
    if (strpos($lower, 'gadget') !== false)      return 'headphones';
    return 'package';
}

$slideGradients = [
    '#1E293B', 
    '#0F172A', 
    '#111827', 
    '#334155', 
    '#1E293B',
    '#0F172A',
    '#111827',
    '#334155',
];

$slides = [];

foreach ($newArrivals as $idx => $arrival) {
    $gradientIdx = ($idx + 1) % count($slideGradients);
    $slides[] = [
        'type'     => 'product',
        'title'    => $arrival['product_name'],
        'desc'     => $arrival['description'] ?: ($arrival['category_name'] ?? 'New product') . ' — now available in store!',
        'btn'      => 'View Product',
        'href'     => 'dashboard.php?open_product=' . $arrival['product_id'],
        'gradient' => $slideGradients[$gradientIdx],
        'icon'     => catIcon($arrival['category_name'] ?? ''),
        'image'    => $arrival['image'] ? SITE_URL . '/' . $arrival['image'] : '',
        'price'    => formatCurrency($arrival['price']),
        'category' => $arrival['category_name'] ?? '',
        'stock'    => (int)$arrival['quantity'],
    ];
}
?>
<?php require_once '../includes/head.php'; ?>
<body>
<div class="page-wrapper">
<?php require_once '../includes/navbar.php'; ?>
<main class="content">

    
    <?php
    $showTour = false;
    if (!isset($_SESSION['tour_shown'])) {
        $showTour = true;
        $_SESSION['tour_shown'] = true;
    }
    ?>

    
    <?php if (!isset($_SESSION['welcome_toast_shown'])): ?>
    <div class="welcome-toast" id="welcomeToast">
        <div class="welcome-toast-icon">
            <i data-lucide="hand-metal"></i>
        </div>
        <div class="welcome-toast-content">
            <div class="welcome-toast-title">Welcome back, <?= $nameParts[0] ?>!</div>
            <div class="welcome-toast-msg">Welcome to <?= htmlspecialchars(getSetting('site_name', SITE_NAME)) ?>. Browse our appliance shop or submit a new repair request.</div>
        </div>
        <div class="welcome-toast-progress" id="welcomeToastProgress"></div>
    </div>
    <?php $_SESSION['welcome_toast_shown'] = true; ?>
    <?php endif; ?>

    
    <?php if (!isset($_SESSION['policy_toast_shown'])): ?>
    <div class="policy-toast" id="policyToast">
        <div class="policy-toast-icon">
            <i data-lucide="store"></i>
        </div>
        <div class="policy-toast-content">
            <div class="policy-toast-title">Store Policy</div>
            <div class="policy-toast-msg">No home service — visit our store to purchase items and for all repairs.</div>
        </div>
        <button class="policy-toast-close" id="policyToastClose" aria-label="Dismiss">
            <i data-lucide="x"></i>
        </button>
        <div class="policy-toast-progress" id="policyToastProgress"></div>
    </div>
    <?php $_SESSION['policy_toast_shown'] = true; ?>
    <?php endif; ?>

    
    <?php if (!empty($slides)): ?>
    <div class="hero-slider" id="heroSlider">
        <button class="slider-arrow slider-prev" id="sliderPrev" aria-label="Previous slide"><i data-lucide="chevron-left"></i></button>
        <div class="slider-track" id="sliderTrack">
            <?php foreach ($slides as $i => $slide): ?>
            <div class="slider-slide<?= $i === 0 ? ' active' : '' ?>" style="background:<?= $slide['gradient'] ?>">
                <?php if (($slide['type'] ?? '') === 'product' && !empty($slide['image'])): ?>
                
                <div class="slide-product-img">
                    <img src="<?= $slide['image'] ?>" alt="<?= sanitize($slide['title']) ?>">
                </div>
                <?php else: ?>
                
                <div class="slide-icon-wrap"><i data-lucide="<?= $slide['icon'] ?>"></i></div>
                <?php endif; ?>
                <div class="slide-body">
                    <?php if (($slide['type'] ?? '') === 'product'): ?>
                    <span class="slide-tag"><i data-lucide="sparkles"></i> New Arrival</span>
                    <?php endif; ?>
                    <h2><?= sanitize($slide['title']) ?></h2>
                    <p><?= sanitize($slide['desc']) ?></p>
                    <?php if (($slide['type'] ?? '') === 'product'): ?>
                    <div class="slide-meta">
                        <span class="slide-price"><?= $slide['price'] ?></span>
                        <?php if (!empty($slide['category'])): ?>
                        <span class="slide-category"><?= sanitize($slide['category']) ?></span>
                        <?php endif; ?>
                        <?php if (($slide['stock'] ?? 0) > 0): ?>
                        <span class="slide-stock"><i data-lucide="check-circle"></i> In Stock</span>
                        <?php else: ?>
                        <span class="slide-stock slide-stock-out"><i data-lucide="x-circle"></i> Sold Out</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <a href="<?= $slide['href'] ?>" class="slide-btn">
                        <i data-lucide="<?= ($slide['type'] ?? '') === 'product' ? 'eye' : 'arrow-right' ?>"></i>
                        <?= $slide['btn'] ?>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="slider-arrow slider-next" id="sliderNext" aria-label="Next slide"><i data-lucide="chevron-right"></i></button>
        <div class="slider-dots" id="sliderDots">
            <?php foreach ($slides as $i => $s): ?>
            <button class="slider-dot<?= $i === 0 ? ' active' : '' ?>" data-index="<?= $i ?>" aria-label="Slide <?= $i+1 ?>"></button>
            <?php endforeach; ?>
        </div>
        <div class="slider-counter" id="sliderCounter">
            <span id="sliderCurrent">1</span> / <span id="sliderTotal"><?= count($slides) ?></span>
        </div>
    </div>
    <?php endif; ?>

    
    <section class="premium-categories" id="premiumCategoriesSection">
        <div class="section-header">
            <h2 class="section-title">Shop by Category</h2>
            <p class="section-subtitle">Browse parts and services available at our physical store (In-store purchase only)</p>
        </div>
        <div class="categories-layout-wrapper">
            
            <div class="category-all-fixed">
                <div class="category-card-premium active" data-cat="">
                    <div class="category-icon-wrapper">
                        <div class="category-icon-bg" style="background: var(--primary);"></div>
                        <i data-lucide="layout-grid"></i>
                    </div>
                    <span class="category-name">All Items</span>
                </div>
            </div>
            
            
            <div class="categories-container-swipe">
                <div class="categories-grid-premium" id="premiumCategories">
                    <?php if (empty($categories)): ?>
                        
                        <?php for($i=0; $i<5; $i++): ?>
                        <div class="category-card-premium skeleton">
                            <div class="category-icon-wrapper shimmer"></div>
                            <div class="category-name-skeleton shimmer"></div>
                        </div>
                        <?php endfor; ?>
                    <?php else: ?>
                        <?php foreach ($categories as $idx => $cat): ?>
                        <div class="category-card-premium" 
                             data-cat="<?= strtolower(sanitize($cat['category_name'])) ?>"
                             style="animation-delay: <?= 0.4 + ($idx * 0.05) ?>s">
                            <div class="category-icon-wrapper <?= !$cat['image'] ? 'no-image' : 'loading' ?>">
                                <div class="category-icon-bg"></div>
                                <?php if ($cat['image']): ?>
                                    <img src="<?= SITE_URL ?>/uploads/<?= $cat['image'] ?>" 
                                         alt="<?= sanitize($cat['category_name']) ?>" 
                                         onload="this.parentElement.classList.remove('loading')"
                                         onerror="this.parentElement.classList.add('error')">
                                    <div class="image-loader shimmer"></div>
                                <?php endif; ?>
                                <i data-lucide="<?= catIcon($cat['category_name']) ?>" class="fallback-icon"></i>
                            </div>
                            <span class="category-name"><?= sanitize($cat['category_name']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    
    <div class="toolbar-row" id="productsSection">
        <div class="toolbar-left">
            <span class="toolbar-title">Products</span>
            <span class="badge badge-teal" id="resultCount"><?= $availableCount ?> available</span>
        </div>
        <div class="toolbar-right">
            <select id="sortFilter" class="input" style="width:auto;padding:8px 36px 8px 12px;">
                <option value="default">Default</option>
                <option value="name-asc">Name A–Z</option>
                <option value="name-desc">Name Z–A</option>
                <option value="price-low">Price Low→High</option>
                <option value="price-high">Price High→Low</option>
                <option value="newest">Newest First</option>
            </select>
        </div>
    </div>

    
    <?php if (empty($products) && empty($soldOut)): ?>
    <div class="empty-state">
        <div class="empty-state-icon"><i data-lucide="package-open"></i></div>
        <p class="empty-state-title">No products available</p>
        <p class="empty-state-sub">Check back later for new products!</p>
    </div>
    <?php else: ?>
    <div class="products-grid" id="productGrid">
        <?php foreach ($products as $product): ?>
        <div class="product-card"
             data-product-id="<?= $product['product_id'] ?>"
             data-name="<?= strtolower(sanitize($product['product_name'])) ?>"
             data-raw-name="<?= sanitize($product['product_name']) ?>"
             data-desc="<?= strtolower(sanitize($product['description'] ?? '')) ?>"
             data-raw-desc="<?= sanitize($product['description'] ?? 'No description available.') ?>"
             data-category="<?= strtolower(sanitize($product['category_name'] ?? '')) ?>"
             data-raw-category="<?= sanitize($product['category_name'] ?? 'Uncategorized') ?>"
             data-price="<?= $product['price'] ?>"
             data-formatted-price="<?= formatCurrency($product['price']) ?>"
             data-date="<?= $product['created_at'] ?>"
             data-status="available"
             data-image="<?= $product['image'] ? SITE_URL . '/' . $product['image'] : '' ?>"
             data-icon="<?= catIcon($product['category_name'] ?? '') ?>"
             data-slug="<?= catSlug($product['category_name'] ?? '') ?>"
             data-stock="<?= $product['quantity'] ?>">
            <div class="product-img <?= catSlug($product['category_name'] ?? '') ?>">
                <?php if ($product['image']): ?>
                <img src="<?= SITE_URL ?>/<?= $product['image'] ?>" alt="<?= sanitize($product['product_name']) ?>">
                <?php else: ?>
                <div class="product-img-placeholder"><i data-lucide="<?= catIcon($product['category_name'] ?? '') ?>"></i></div>
                <?php endif; ?>
                <?php if ($product['category_name']): ?>
                <span class="product-cat-badge badge badge-teal"><?= sanitize($product['category_name']) ?></span>
                <?php endif; ?>
                <span class="product-stock-badge"><?= $product['quantity'] ?> left</span>
            </div>
            <div class="product-body">
                <div class="product-name"><?= sanitize($product['product_name']) ?></div>
                <div class="product-footer">
                    <span class="product-price"><?= formatCurrency($product['price']) ?></span>
                    <span class="badge badge-emerald"><i data-lucide="check"></i> In Stock</span>
                </div>
                <div class="product-actions">
                    <button type="button" class="product-action-btn btn-view-product" title="View Details" style="width: 100%; justify-content: center;"><i data-lucide="eye"></i> View Details</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        
        <?php foreach ($soldOut as $product): ?>
        <div class="product-card sold-out"
             data-product-id="<?= $product['product_id'] ?>"
             data-name="<?= strtolower(sanitize($product['product_name'])) ?>"
             data-raw-name="<?= sanitize($product['product_name']) ?>"
             data-desc="<?= strtolower(sanitize($product['description'] ?? '')) ?>"
             data-raw-desc="<?= sanitize($product['description'] ?? 'No description available.') ?>"
             data-category="<?= strtolower(sanitize($product['category_name'] ?? '')) ?>"
             data-raw-category="<?= sanitize($product['category_name'] ?? 'Uncategorized') ?>"
             data-price="<?= $product['price'] ?>"
             data-formatted-price="<?= formatCurrency($product['price']) ?>"
             data-date="<?= $product['created_at'] ?>"
             data-status="sold-out"
             data-image="<?= $product['image'] ? SITE_URL . '/' . $product['image'] : '' ?>"
             data-icon="<?= catIcon($product['category_name'] ?? '') ?>"
             data-slug="<?= catSlug($product['category_name'] ?? '') ?>"
             data-stock="0">
            <div class="product-img <?= catSlug($product['category_name'] ?? '') ?>">
                <?php if ($product['image']): ?>
                <img src="<?= SITE_URL ?>/<?= $product['image'] ?>" alt="<?= sanitize($product['product_name']) ?>">
                <?php else: ?>
                <div class="product-img-placeholder"><i data-lucide="<?= catIcon($product['category_name'] ?? '') ?>"></i></div>
                <?php endif; ?>
                <?php if ($product['category_name']): ?>
                <span class="product-cat-badge badge badge-rose"><?= sanitize($product['category_name']) ?></span>
                <?php endif; ?>
                <span class="product-stock-badge">0 left</span>
            </div>
            <div class="product-body">
                <div class="product-name"><?= sanitize($product['product_name']) ?></div>
                <div class="product-footer">
                    <span class="product-price" style="color:var(--text-tertiary)"><?= formatCurrency($product['price']) ?></span>
                    <span class="badge badge-rose"><i data-lucide="ban"></i> Out of Stock</span>
                </div>
                <div class="product-actions">
                    <button type="button" class="product-action-btn btn-view-product" title="View Details" style="width: 100%; justify-content: center;"><i data-lucide="eye"></i> View Details</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>
</div>

<script src="<?= SITE_URL ?>/assets/js/main.js?v=<?= filemtime(__DIR__ . '/../assets/js/main.js') ?>" defer></script>
<script>
document.addEventListener('DOMContentLoaded', () => {

    
    const urlParams = new URLSearchParams(window.location.search);
    const openProductId = urlParams.get('open_product');
    if (openProductId) {
        
        setTimeout(() => {
            const productCard = document.querySelector(`.product-card[data-product-id="${openProductId}"]`);
            if (productCard) {
                const viewBtn = productCard.querySelector('.btn-view-product');
                if (viewBtn) viewBtn.click();
            }
        }, 300);
        
        
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    
    const policyToast = document.getElementById('policyToast');
    const policyClose = document.getElementById('policyToastClose');
    const policyProgress = document.getElementById('policyToastProgress');
    if (policyToast) {
        
        setTimeout(() => {
            policyToast.classList.add('show');
            policyProgress.classList.add('running');
        }, 1500);

        
        const autoDismiss = setTimeout(() => {
            policyToast.classList.remove('show');
            policyToast.classList.add('hiding');
            setTimeout(() => policyToast.remove(), 400);
        }, 9500);

        policyClose.addEventListener('click', () => {
            clearTimeout(autoDismiss);
            policyToast.classList.remove('show');
            policyToast.classList.add('hiding');
            setTimeout(() => policyToast.remove(), 400);
        });
    }

    
    const slides = document.querySelectorAll('.slider-slide');
    const dots   = document.querySelectorAll('.slider-dot');
    const counterCurrent = document.getElementById('sliderCurrent');
    let current  = 0;
    let autoTimer;

    function goTo(idx) {
        slides[current].classList.remove('active');
        dots[current]?.classList.remove('active');
        current = (idx + slides.length) % slides.length;
        slides[current].classList.add('active');
        dots[current]?.classList.add('active');
        if (counterCurrent) counterCurrent.textContent = current + 1;
    }
    function next() { goTo(current + 1); }
    function prev() { goTo(current - 1); }
    function startAuto() { autoTimer = setInterval(next, 4000); }
    function stopAuto() { clearInterval(autoTimer); }

    document.getElementById('sliderNext')?.addEventListener('click', () => { stopAuto(); next(); startAuto(); });
    document.getElementById('sliderPrev')?.addEventListener('click', () => { stopAuto(); prev(); startAuto(); });
    dots.forEach(d => d.addEventListener('click', () => { stopAuto(); goTo(+d.dataset.index); startAuto(); }));
    if (slides.length > 1) startAuto();

    
    const catCards = document.querySelectorAll('.category-card-premium:not(.skeleton)');
    const grid = document.getElementById('productGrid');
    const countBadge = document.getElementById('resultCount');
    let activeCategory = '';

    catCards.forEach(card => {
        card.addEventListener('click', () => {
            catCards.forEach(c => c.classList.remove('active'));
            card.classList.add('active');
            activeCategory = card.dataset.cat;
            applyFilters();
            
            
            if (window.gsap) {
                gsap.to(card.querySelector('.category-icon-wrapper'), {
                    scale: 0.9,
                    duration: 0.1,
                    yoyo: true,
                    repeat: 1
                });
            }
            
            
            if (window.innerWidth < 768) {
                document.getElementById('productsSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    
    const sortSelect = document.getElementById('sortFilter');
    sortSelect?.addEventListener('change', applyFilters);

    function applyFilters() {
        if (!grid) return;
        const cards = [...grid.querySelectorAll('.product-card')];
        const catVal = activeCategory.toLowerCase();
        const sortVal = sortSelect?.value || 'default';

        
        cards.forEach(card => {
            const cat = (card.dataset.category || '').toLowerCase();
            card.style.display = (!catVal || cat.includes(catVal)) ? '' : 'none';
        });

        
        const visible = cards.filter(c => c.style.display !== 'none');
        visible.sort((a, b) => {
            switch (sortVal) {
                case 'name-asc':   return a.dataset.name.localeCompare(b.dataset.name);
                case 'name-desc':  return b.dataset.name.localeCompare(a.dataset.name);
                case 'price-low':  return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
                case 'price-high': return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
                case 'newest':     return new Date(b.dataset.date) - new Date(a.dataset.date);
                default:           return 0;
            }
        });

        
        const avail   = visible.filter(c => c.dataset.status === 'available');
        const sold    = visible.filter(c => c.dataset.status !== 'available');
        [...avail, ...sold].forEach(c => grid.appendChild(c));

        
        if (countBadge) {
            countBadge.textContent = avail.length + ' available';
        }
    }
    
    const welcomeToast = document.getElementById('welcomeToast');
    if (welcomeToast) {
        
        setTimeout(() => {
            welcomeToast.classList.add('show');
            const welcomeProgress = document.getElementById('welcomeToastProgress');
            if (welcomeProgress) welcomeProgress.classList.add('running');
            
            
            setTimeout(() => {
                welcomeToast.classList.remove('show');
                welcomeToast.classList.add('hiding');
                setTimeout(() => welcomeToast.remove(), 400);
            }, 3000);
        }, 100);
    }
});
</script>

<style>

.welcome-toast {
    position: fixed;
    top: 80px;
    right: 28px;
    z-index: 900;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    width: 380px;
    max-width: calc(100vw - 32px);
    padding: 20px 22px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: 0 16px 48px rgba(30,58,138,0.18), 0 4px 12px rgba(0,0,0,0.08);
    transform: translateX(120%);
    opacity: 0;
    transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
    overflow: hidden;
}
.welcome-toast.show {
    transform: translateX(0);
    opacity: 1;
}
.welcome-toast.hiding {
    transform: translateX(120%);
    opacity: 0;
    transition: transform 0.3s ease, opacity 0.2s ease;
}

.welcome-toast-icon {
    width: 40px; height: 40px; border-radius: 10px;
    background: #10B981;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.welcome-toast-icon svg { width: 20px; height: 20px; color: #fff; }

.welcome-toast-content { flex: 1; min-width: 0; }
.welcome-toast-title {
    font-family: 'Syne', sans-serif; font-weight: 700;
    font-size: 15px; color: #0F172A; margin-bottom: 3px;
}
.welcome-toast-msg {
    font-size: 13px; color: #64748B; line-height: 1.45;
}

.welcome-toast-progress {
    position: absolute; bottom: 0; left: 0; right: 0;
    height: 3px; background: #10B981;
    transform: scaleX(1); transform-origin: left;
    border-radius: 0 0 14px 14px;
}
.welcome-toast-progress.running {
    animation: toast-progress 3s linear forwards;
}

.policy-toast {
    position: fixed;
    bottom: 28px;
    left: 50%;
    z-index: 900;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    width: 420px;
    max-width: calc(100vw - 32px);
    padding: 20px 22px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: 0 16px 48px rgba(30,58,138,0.18), 0 4px 12px rgba(0,0,0,0.08);
    transform: translate(-50%, 140%);
    opacity: 0;
    transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
    overflow: hidden;
}
.policy-toast.show {
    transform: translate(-50%, 0);
    opacity: 1;
}
.policy-toast.hiding {
    transform: translate(-50%, 140%);
    opacity: 0;
    transition: transform 0.3s ease, opacity 0.2s ease;
}

.policy-toast-icon {
    width: 40px; height: 40px; border-radius: 10px;
    background: var(--primary);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.policy-toast-icon svg { width: 20px; height: 20px; color: #fff; }

.policy-toast-content { flex: 1; min-width: 0; }
.policy-toast-title {
    font-family: 'Syne', sans-serif; font-weight: 700;
    font-size: 14px; color: #0F172A; margin-bottom: 3px;
}
.policy-toast-msg {
    font-size: 12.5px; color: #64748B; line-height: 1.45;
}

.policy-toast-close {
    background: none; border: none; cursor: pointer;
    width: 28px; height: 28px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    color: #94A3B8; transition: all 0.15s; flex-shrink: 0;
}
.policy-toast-close:hover { background: #F1F5F9; color: #475569; }
.policy-toast-close svg { width: 16px; height: 16px; }

.policy-toast-progress {
    position: absolute; bottom: 0; left: 0; right: 0;
    height: 3px; background: var(--accent);
    transform: scaleX(0); transform-origin: left;
    border-radius: 0 0 14px 14px;
}
.policy-toast-progress.running {
    animation: toast-progress 8s linear forwards;
}
@keyframes toast-progress {
    from { transform: scaleX(1); }
    to   { transform: scaleX(0); }
}

.hero-slider {
    position: relative;
    border-radius: 20px;
    overflow: hidden;
    margin-bottom: 28px;
    box-shadow: 0 12px 40px rgba(30,58,138,0.16);
}
.slider-track { position: relative; height: 520px; }
.slider-slide {
    position: absolute; inset: 0;
    display: flex; align-items: center; gap: 32px;
    padding: 40px 56px;
    opacity: 0; transform: scale(0.95) translateX(40px);
    transition: opacity 0.7s ease, transform 0.7s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    pointer-events: none;
}
.slider-slide.active {
    opacity: 1; transform: scale(1) translateX(0);
    pointer-events: auto;
}

.slider-slide::before {
    content: ''; position: absolute;
    top: -60px; right: -60px;
    width: 260px; height: 260px;
    background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 70%);
    border-radius: 50%; pointer-events: none;
}
.slider-slide::after {
    content: ''; position: absolute;
    bottom: -40px; left: 20%;
    width: 180px; height: 180px;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
    border-radius: 50%; pointer-events: none;
}

.slider-slide.active .slide-body::after {
    content: ''; position: absolute;
    top: 0; left: -100%; width: 60%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);
    animation: shimmer-sweep 3s ease-in-out infinite;
    pointer-events: none;
}
@keyframes shimmer-sweep {
    0%   { left: -100%; }
    100% { left: 200%; }
}

.slide-icon-wrap {
    width: 180px; height: 180px;
    background: rgba(255,255,255,0.15);
    border-radius: 24px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,0.18);
    box-shadow: 0 8px 24px rgba(0,0,0,0.1);
    animation: float-gentle 4s ease-in-out infinite;
}
.slide-icon-wrap svg { width: 80px; height: 80px; color: #fff; }
@keyframes float-gentle {
    0%, 100% { transform: translateY(0); }
    50%      { transform: translateY(-6px); }
}

.slide-product-img {
    width: 320px; height: 320px;
    border-radius: 20px;
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; overflow: hidden;
    border: 2px solid rgba(255,255,255,0.25);
    box-shadow: 0 12px 32px rgba(0,0,0,0.2);
    animation: float-gentle 4s ease-in-out infinite;
}
.slide-product-img img {
    width: 100%; height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}
.slider-slide.active .slide-product-img img { transform: scale(1.08); }

.slide-body { flex: 1; min-width: 0; position: relative; }
.slide-body h2 {
    color: #fff; font-family: 'Syne', sans-serif;
    font-weight: 800; font-size: 48px; margin: 0 0 16px;
    text-shadow: 0 2px 10px rgba(0,0,0,0.2);
    line-height: 1.2;
}
.slide-body p {
    color: rgba(255,255,255,0.9); font-size: 18px;
    margin: 0 0 16px; line-height: 1.6;
    display: -webkit-box; -webkit-line-clamp: 2;
    -webkit-box-orient: vertical; overflow: hidden;
    max-width: 420px;
}

.slide-tag {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 14px; border-radius: 20px;
    background: rgba(255,255,255,0.2); backdrop-filter: blur(8px);
    color: #fff; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-bottom: 12px;
    border: 1px solid rgba(255,255,255,0.25);
    animation: tag-glow 2s ease-in-out infinite alternate;
}
.slide-tag svg { width: 12px; height: 12px; }
@keyframes tag-glow {
    from { box-shadow: 0 0 6px rgba(255,255,255,0.1); }
    to   { box-shadow: 0 0 16px rgba(255,255,255,0.25); }
}

.slide-meta {
    display: flex; align-items: center; gap: 12px;
    flex-wrap: wrap; margin-bottom: 16px;
}
.slide-price {
    font-family: 'Syne', sans-serif; font-weight: 800;
    font-size: 24px; color: #fff;
    text-shadow: 0 2px 8px rgba(0,0,0,0.25);
}
.slide-category {
    padding: 4px 12px; border-radius: 8px;
    background: rgba(255,255,255,0.2); color: #fff;
    font-size: 12px; font-weight: 600;
    backdrop-filter: blur(4px);
}
.slide-stock {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 12px; border-radius: 8px;
    background: rgba(16,185,129,0.35); color: #D1FAE5;
    font-size: 12px; font-weight: 600;
}
.slide-stock svg { width: 13px; height: 13px; }
.slide-stock-out { background: rgba(239,68,68,0.35); color: #FECACA; }

.slide-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 28px; border-radius: 12px;
    background: #fff; color: var(--primary);
    font-weight: 700; font-size: 14px;
    text-decoration: none;
    transition: all 0.25s ease;
    box-shadow: 0 4px 16px rgba(0,0,0,0.12), 0 0 0 0 rgba(255,255,255,0);
}
.slide-btn svg { width: 16px; height: 16px; }
.slide-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.2), 0 0 20px rgba(255,255,255,0.15);
}

.slider-arrow {
    position: absolute; top: 50%; transform: translateY(-50%);
    z-index: 10; width: 46px; height: 46px; border-radius: 50%;
    background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.22);
    color: #fff; display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.25s; backdrop-filter: blur(10px);
}
.slider-arrow svg { width: 22px; height: 22px; }
.slider-arrow:hover {
    background: rgba(255,255,255,0.35);
    transform: translateY(-50%) scale(1.1);
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}
.slider-prev { left: 16px; }
.slider-next { right: 16px; }

.slider-dots {
    position: absolute; bottom: 18px; left: 50%; transform: translateX(-50%);
    display: flex; gap: 7px; z-index: 10;
}
.slider-dot {
    width: 10px; height: 10px; border-radius: 50%;
    background: rgba(255,255,255,0.3); border: none; cursor: pointer;
    transition: all 0.3s ease;
}
.slider-dot.active {
    background: #fff; width: 28px; border-radius: 5px;
    box-shadow: 0 0 10px rgba(255,255,255,0.5);
}

.slider-counter {
    position: absolute; top: 18px; right: 18px;
    padding: 6px 16px; border-radius: 20px;
    background: rgba(0,0,0,0.3); backdrop-filter: blur(10px);
    color: #fff; font-size: 12px; font-weight: 600;
    z-index: 10; border: 1px solid rgba(255,255,255,0.1);
}

.premium-categories {
    margin-bottom: 40px;
    padding: 0 4px;
    opacity: 1 !important;
    visibility: visible !important;
}
.section-header {
    margin-bottom: 24px;
}
.section-title {
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    font-size: 24px;
    color: #0F172A;
    margin-bottom: 4px;
}
.section-subtitle {
    color: #64748B;
    font-size: 14px;
}

.categories-layout-wrapper {
    display: flex;
    align-items: flex-start;
    gap: 24px;
    position: relative;
    
    padding-top: 15px;
}

.category-all-fixed {
    flex-shrink: 0;
    width: 120px;
    display: flex;
    justify-content: center;
    border-right: 1px solid var(--border);
    padding-right: 24px;
}

.categories-container-swipe {
    flex: 1;
    overflow-x: auto;
    scrollbar-width: none; 
}
.categories-container-swipe::-webkit-scrollbar {
    display: none; 
}

.categories-grid-premium {
    display: flex;
    gap: 24px;
    min-width: max-content;
}

@media (min-width: 1024px) {
    .categories-grid-premium {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 24px;
        min-width: 0;
    }
}

.category-card-premium {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    width: 120px;
    opacity: 0;
    animation: fadeInUpPop 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
}

@keyframes fadeInUpPop {
    0% { 
        opacity: 0; 
        transform: translateY(30px) scale(0.9); 
    }
    100% { 
        opacity: 1; 
        transform: translateY(0) scale(1); 
    }
}

.category-card-premium:hover .category-icon-wrapper {
    transform: translateY(-4px);
    border-color: var(--accent);
    box-shadow: 0 8px 20px rgba(220, 38, 38, 0.1);
    z-index: 10;
}

.category-card-premium.active .category-icon-wrapper {
    transform: translateY(-4px);
    border-color: var(--accent);
    box-shadow: 0 8px 20px rgba(220, 38, 38, 0.15);
}

.category-icon-wrapper {
    width: 88px;
    height: 88px;
    border-radius: 50%;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 1;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    border: 2px solid var(--border);
    overflow: hidden;
}

.category-icon-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease, opacity 0.3s ease;
}

.category-icon-wrapper i {
    width: 38px;
    height: 38px;
    color: #1e293b; 
    transition: all 0.3s ease;
}

.category-icon-wrapper.loading img {
    opacity: 0;
}

.category-icon-wrapper.loading .image-loader {
    display: block;
}

.image-loader {
    display: none;
    position: absolute;
    inset: 0;
    background: #f1f5f9;
}

.fallback-icon {
    display: none;
}

.category-icon-wrapper.no-image .fallback-icon,
.category-icon-wrapper.error .fallback-icon {
    display: block;
}

.category-icon-wrapper.error img {
    display: none;
}

.category-name {
    font-weight: 700;
    font-size: 13.5px;
    color: #334155;
    text-align: center;
    font-family: 'DM Sans', sans-serif;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.shimmer {
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.category-card-premium.skeleton {
    cursor: default;
}

.category-name-skeleton {
    width: 70px;
    height: 14px;
    border-radius: 4px;
}

.category-card-premium:hover .category-icon-wrapper img,
.category-card-premium.active .category-icon-wrapper img {
    transform: scale(1.12);
}

.category-card-premium:hover .category-name,
.category-card-premium.active .category-name {
    color: var(--primary);
    transform: scale(1.05);
}

.category-card-premium.active .category-icon-bg {
    background: var(--primary);
}

.category-card-premium.active i {
    color: #fff;
}

.category-card-premium::after {
    content: '';
    position: absolute;
    bottom: -15px;
    width: 6px;
    height: 6px;
    background: var(--primary);
    border-radius: 50%;
    opacity: 0;
    transform: scale(0);
    transition: all 0.3s ease;
}

.category-card-premium.active::after {
    opacity: 1;
    transform: scale(1);
}

.product-actions {
    display: flex; gap: 6px; margin-top: 10px;
    padding-top: 10px; border-top: 1px solid var(--border);
}
.product-action-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 6px 14px; border-radius: var(--radius-btn);
    font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none;
    transition: all 0.2s; border: 1px solid var(--border);
    color: var(--text-muted); background: var(--bg-white);
}
.product-action-btn svg { width: 13px; height: 13px; }
.product-action-btn:hover { border-color: var(--gold); color: var(--gold); }
.btn-view-product {
    flex: 1; justify-content: center;
    background: var(--primary); color: #fff; border-color: var(--primary);
}
.btn-view-product:hover { background: var(--primary-dark); color: #fff; border-color: var(--primary-dark); }
.btn-icon { flex: 0 0 auto; padding: 6px 8px; }
.product-action-btn.disabled {
    opacity: 0.4; pointer-events: none;
}

.product-card.sold-out {
    opacity: 0.7;
}
.product-card.sold-out .product-img { filter: grayscale(0.5); }

.cat-gadgets { background: #FFF7ED; }

.toolbar-left { display: flex; align-items: center; gap: 10px; }
.toolbar-title { font-family: 'Syne', sans-serif; font-weight: 800; font-size: 20px; color: #000; }

@media (max-width: 640px) {
    .slider-slide { padding: 24px 20px; gap: 20px; flex-direction: column; text-align: center; }
    .slide-icon-wrap { width: 80px; height: 80px; }
    .slide-icon-wrap svg { width: 40px; height: 40px; }
    .slide-product-img { width: 160px; height: 160px; }
    .slide-body h2 { font-size: 24px; }
    .slide-body p  { font-size: 14px; margin-bottom: 12px; -webkit-line-clamp: 2; max-width: none; }
    .slide-meta { justify-content: center; }
    .slide-price { font-size: 20px; }
    .slider-arrow { width: 36px; height: 36px; }
    .slider-arrow svg { width: 18px; height: 18px; }
    .slider-prev { left: 8px; }
    .slider-next { right: 8px; }
    .slider-track { height: 420px; }
    .slider-counter { top: 10px; right: 10px; font-size: 11px; padding: 4px 10px; }
    .toolbar-row { flex-direction: column; align-items: stretch; gap: 12px; }
    .toolbar-right { flex-direction: column; }
    .product-actions { flex-wrap: wrap; }

    .policy-toast { width: calc(100vw - 24px); }
}
</style>

<?php if ($showTour): ?>

<div id="portalTour" class="portal-tour" style="display: none;">
    <div id="tourTooltip" class="tour-tooltip">
        <div class="tour-header">
            <span class="tour-step-indicator">Step 1 of 5</span>
            <button class="tour-close-btn" onclick="endTour()">&times;</button>
        </div>
        <h4 id="tourTitle" class="tour-title">Welcome to G-MORE</h4>
        <p id="tourText" class="tour-text">Let's take a quick 1-minute tour to help you get familiar with the portal features.</p>
        <div class="tour-actions">
            <button id="tourSkip" class="tour-btn btn-secondary" onclick="endTour()">Skip Tour</button>
            <div class="tour-nav-btns">
                <button id="tourPrev" class="tour-btn btn-secondary" onclick="prevStep()" disabled>Back</button>
                <button id="tourNext" class="tour-btn btn-accent" onclick="nextStep()">Next</button>
            </div>
        </div>
    </div>
</div>

<style>

.portal-tour {
    position: fixed;
    inset: 0;
    z-index: 1000000;
    pointer-events: none;
}

.tour-tooltip {
    position: fixed;
    width: 340px;
    background: #ffffff;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.2), 0 0 0 1px rgba(15, 23, 42, 0.05);
    z-index: 1000002;
    pointer-events: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    
    
    left: 24px;
    bottom: 24px;
}

.tour-tooltip.placement-center {
    top: 50%;
    left: 50%;
    bottom: auto;
    transform: translate(-50%, -50%);
}

@media (max-width: 768px) {
    .tour-tooltip {
        width: calc(100vw - 32px);
        left: 16px;
        bottom: 16px;
        transform: none;
    }
    .tour-tooltip.placement-center {
        top: 50%;
        left: 50%;
        bottom: auto;
        transform: translate(-50%, -50%);
        width: calc(100vw - 32px);
    }
}

.tour-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.tour-step-indicator {
    font-size: 11px;
    font-weight: 700;
    color: var(--accent);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.tour-close-btn {
    background: none;
    border: none;
    font-size: 20px;
    color: var(--text-muted);
    cursor: pointer;
    line-height: 1;
}
.tour-title {
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    font-size: 16px;
    color: #0F172A;
    margin: 0;
}
.tour-text {
    font-size: 13.5px;
    color: var(--text-muted);
    line-height: 1.5;
    margin: 0;
}
.tour-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 8px;
}
.tour-nav-btns {
    display: flex;
    gap: 8px;
}
.tour-btn {
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    border: 1px solid var(--border);
    display: inline-block !important;
}
#tourNext {
    display: inline-block !important;
    visibility: visible !important;
}
.tour-btn.btn-secondary {
    background: #F8FAFC;
    color: var(--text-muted);
}
.tour-btn.btn-secondary:hover {
    background: #F1F5F9;
    color: var(--text-primary);
}
.tour-btn.btn-accent {
    background: #DC2626 !important;
    color: #ffffff !important;
    border-color: #DC2626 !important;
}
.tour-btn.btn-accent:hover {
    background: #B91C1C !important;
    border-color: #B91C1C !important;
    color: #ffffff !important;
}
.tour-btn:disabled {
    opacity: 0.5;
    pointer-events: none;
}

.tour-highlight {
    z-index: 99999 !important;
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.75), 0 20px 40px rgba(0, 0, 0, 0.4) !important;
    pointer-events: none !important;
    transition: all 0.3s ease !important;
}

.hero-slider.tour-highlight,
#premiumCategoriesSection.tour-highlight,
#productsSection.tour-highlight,
.navbar-search.tour-highlight,
#notifBellWrap.tour-highlight,
a[title="My Repairs"].tour-highlight {
    position: relative !important;
}
</style>

<script>
const tourSteps = [
    {
        title: "Welcome to G-MORE!",
        text: "Let's take a quick 1-minute tour to help you get familiar with the portal features.",
        target: null,
        placement: "center"
    },
    {
        title: "Featured Items & Arrivals",
        text: "Check out featured tools, new parts arrivals, and announcements right here in the hero slider.",
        target: ".hero-slider",
        placement: "bottom"
    },
    {
        title: "Shop by Category",
        text: "Filter items by category instantly. Remember, 'All Items' is docked on the left for easy navigation.",
        target: ".categories-layout-wrapper",
        placement: "bottom"
    },
    {
        title: "Products & Parts Catalog",
        text: "Click 'View Details' on any product card to see its specifications, pricing, and physical store availability.",
        target: "#productsSection",
        placement: "top"
    },
    {
        title: "Global Search Dropdown",
        text: "The search bar has typed 'charger' to demonstrate. It automatically searches repairs and catalog parts in real-time as you type.",
        target: ".navbar-search",
        placement: "bottom"
    },
    {
        title: "Request a Repair Form",
        text: "The request form has opened and pre-filled with demo data. Describe your appliance type and issues to submit a service job.",
        target: ".nr-modal",
        placement: "bottom"
    },
    {
        title: "My Repairs History",
        text: "Click this wrench icon to track your active repairs, view status progress, download invoices, or make secure online payments.",
        target: "a[title='My Repairs']",
        placement: "bottom"
    },
    {
        title: "Notification Center",
        text: "Hover over or click the bell icon to view real-time status updates on your repairs, payments, and warranties.",
        target: "#notifBellWrap",
        placement: "bottom"
    },
    {
        title: "Live Chat Support Panel",
        text: "The chat support panel has opened to show you. Type a message here to start a live support conversation directly with our staff.",
        target: "#cwPanel",
        placement: "top"
    }
];

let currentStepIdx = 0;

function startTour() {
    const tourContainer = document.getElementById('portalTour');
    if (!tourContainer) return;
    tourContainer.style.display = 'block';
    showStep(0);
}

function showStep(index) {
    
    if (currentStepIdx === 4 && index !== 4) {
        const searchInput = document.getElementById('globalSearchInput');
        if (searchInput) {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
        }
    }
    if (currentStepIdx === 5 && index !== 5) {
        const form = document.getElementById('nrForm');
        if (form) form.reset();
        const nrOverlay = document.getElementById('nrOverlay');
        if (nrOverlay) nrOverlay.classList.remove('open');
    }
    if (currentStepIdx === 8 && index !== 8) {
        const cwPanel = document.getElementById('cwPanel');
        const cwFab = document.getElementById('cwFab');
        if (cwPanel) cwPanel.classList.remove('open');
        if (cwFab) cwFab.classList.remove('hidden');
    }

    currentStepIdx = index;
    const step = tourSteps[index];
    const tooltip = document.getElementById('tourTooltip');
    const titleEl = document.getElementById('tourTitle');
    const textEl = document.getElementById('tourText');
    const stepIndicator = tooltip.querySelector('.tour-step-indicator');
    const prevBtn = document.getElementById('tourPrev');
    const nextBtn = document.getElementById('tourNext');
    
    
    document.querySelectorAll('.tour-highlight').forEach(el => el.classList.remove('tour-highlight'));
    
    
    stepIndicator.innerText = `Step ${index + 1} of ${tourSteps.length}`;
    titleEl.innerText = step.title;
    textEl.innerText = step.text;
    
    
    prevBtn.disabled = index === 0;
    nextBtn.innerText = index === tourSteps.length - 1 ? "Finish" : "Next";
    nextBtn.style.setProperty('display', 'inline-block', 'important');
    
    
    tooltip.className = 'tour-tooltip placement-' + step.placement;
    
    
    if (index === 4) {
        const searchInput = document.getElementById('globalSearchInput');
        if (searchInput) {
            searchInput.value = 'charger';
            searchInput.dispatchEvent(new Event('input'));
        }
    }
    if (index === 5) {
        if (window._openNewRequest) {
            window._openNewRequest();
            
            const typeEl = document.getElementById('nrApplianceType');
            const brandEl = document.getElementById('nrBrand');
            const modelEl = document.getElementById('nrModel');
            const descEl = document.getElementById('nrProbDesc');
            
            if (typeEl) typeEl.value = "Refrigerator";
            if (brandEl) brandEl.value = "Samsung";
            if (modelEl) modelEl.value = "RT38K5590S8";
            if (descEl) descEl.value = "The compressor is making a loud clicking sound and it is not cooling properly.";
        }
    }
    if (index === 8) {
        const cwPanel = document.getElementById('cwPanel');
        const cwFab = document.getElementById('cwFab');
        if (cwPanel) cwPanel.classList.add('open');
        if (cwFab) cwFab.classList.add('hidden');
    }

    if (step.target) {
        const targetEl = document.querySelector(step.target);
        if (targetEl) {
            targetEl.classList.add('tour-highlight');
            
            targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}

function nextStep() {
    if (currentStepIdx < tourSteps.length - 1) {
        showStep(currentStepIdx + 1);
    } else {
        endTour();
    }
}

function prevStep() {
    if (currentStepIdx > 0) {
        showStep(currentStepIdx - 1);
    }
}

function endTour() {
    document.querySelectorAll('.tour-highlight').forEach(el => el.classList.remove('tour-highlight'));
    
    
    const searchInput = document.getElementById('globalSearchInput');
    if (searchInput) {
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
    }
    
    
    const form = document.getElementById('nrForm');
    if (form) form.reset();
    const nrOverlay = document.getElementById('nrOverlay');
    if (nrOverlay) nrOverlay.classList.remove('open');
    
    
    const cwPanel = document.getElementById('cwPanel');
    const cwFab = document.getElementById('cwFab');
    if (cwPanel) cwPanel.classList.remove('open');
    if (cwFab) cwFab.classList.remove('hidden');

    const tourContainer = document.getElementById('portalTour');
    if (tourContainer) {
        tourContainer.style.display = 'none';
    }
}

window.addEventListener('load', () => {
    setTimeout(startTour, 1000);
});
</script>
<?php endif; ?>

<?php require_once '../includes/product-modal.php'; ?>
</body>
</html>
