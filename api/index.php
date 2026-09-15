<?php include '../extends/header.php'; include '../extends/style.php'; include '../extends/keranjang.php'; ?>    

<main class="max-w-7xl mx-auto px-6 pt-6 space-y-6">        
        <!-- Search & Dynamic Filter Section -->
        <div class="flex flex-col md:flex-row items-center justify-between gap-4 bg-slate-900/80 p-4 rounded-2xl border border-slate-800">
            <div class="relative w-full md:w-96">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" id="search-makanan" onkeyup="renderFoodProducts()" placeholder="Cari Indomie, Ayam Geprek, Nasi Goreng..." 
                    class="w-full bg-slate-950 text-slate-100 placeholder-slate-500 text-xs pl-10 pr-4 py-2.5 rounded-xl border border-slate-800 focus:outline-none focus:border-brand-500 transition-all">
            </div>

            <!-- Category Filter Buttons fetched dynamically -->
            <div class="flex items-center gap-2 overflow-x-auto hide-scrollbar w-full md:w-auto">
                <button onclick="selectFoodCategory('all')" class="food-cat-btn active px-4 py-2 rounded-xl text-xs font-bold whitespace-nowrap bg-brand-600 text-white shadow-md shadow-brand-600/30" data-cat="all">
                    🔥 Semua Makanan
                </button>
                <?php foreach ($food_categories as $cat): ?>
                <button onclick="selectFoodCategory('<?= htmlspecialchars($cat['slug']) ?>')" class="food-cat-btn px-4 py-2 rounded-xl text-xs font-bold whitespace-nowrap bg-slate-950 text-slate-400 border border-slate-800 hover:text-white transition-all" data-cat="<?= htmlspecialchars($cat['slug']) ?>">
                    <?= htmlspecialchars($cat['name']) ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Grid Produk Makanan -->
        <div id="food-product-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
            <!-- Injected via JS -->
        </div>

    </main>

    <div id="floating-cart" class="fixed bottom-6 left-1/2 -translate-x-1/2 w-full max-w-xl px-4 z-40 transition-all transform translate-y-28 opacity-0 pointer-events-none">
        <div class="bg-slate-900/95 backdrop-blur-md border border-brand-500/40 p-3.5 rounded-2xl shadow-2xl flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 pl-2">
                <div class="relative bg-brand-600/20 text-brand-400 p-2.5 rounded-xl border border-brand-500/30">
                    <i class="fa-solid fa-basket-shopping text-base"></i>
                    <span id="cart-count-badge" class="absolute -top-1.5 -right-1.5 bg-brand-500 text-slate-950 font-extrabold text-[10px] w-4 h-4 rounded-full flex items-center justify-center shadow-md">0</span>
                </div>
                <div>
                    <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Keranjang Pesanan <?= htmlspecialchars($active_pc['pc_name']) ?></p>
                    <p id="cart-total-price" class="text-sm font-extrabold text-emerald-400">Rp 0</p>
                </div>
            </div>
            <button onclick="openCartModal()" class="bg-gradient-to-r from-brand-500 to-indigo-600 hover:from-brand-600 hover:to-indigo-700 text-white font-bold text-xs px-5 py-2.5 rounded-xl shadow-lg flex items-center gap-2 active:scale-95 transition-transform">
                <span>Lihat Keranjang</span>
                <i class="fa-solid fa-arrow-right text-[10px]"></i>
            </button>
        </div>
    </div>

    <!-- MODAL DETAIL PRODUK MAKANAN -->
    <div id="detail-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-slate-900 border border-slate-800 w-full max-w-lg rounded-2xl overflow-hidden shadow-2xl transform scale-95 transition-transform duration-300" id="detail-modal-card">
            <div class="relative h-56 bg-slate-800">
                <img id="modal-img" src="" alt="Detail Makanan" class="w-full h-full object-cover">
                <button onclick="closeDetailModal()" class="absolute top-3 right-3 bg-slate-950/70 text-white w-8 h-8 rounded-full flex items-center justify-center border border-slate-700/50 hover:bg-slate-900">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
                <span id="modal-cat" class="absolute bottom-3 left-3 bg-brand-500 text-slate-950 font-extrabold text-[10px] px-2.5 py-1 rounded-lg uppercase"></span>
            </div>
            
            <div class="p-5 space-y-4">
                <div>
                    <h2 id="modal-title" class="text-lg font-extrabold text-white"></h2>
                    <p id="modal-desc" class="text-xs text-slate-400 mt-1 leading-relaxed"></p>
                    <p id="modal-price" class="text-base font-extrabold text-emerald-400 mt-2"></p>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-300 uppercase tracking-wider block">Pilih Tingkat Pedas / Varian:</label>
                    <div class="grid grid-cols-3 gap-2">
                        <button onclick="selectVariant(this, 'Pedas Normal')" class="variant-btn active bg-brand-600 border border-brand-500 text-white text-[11px] font-bold py-2 rounded-xl transition">Normal</button>
                        <button onclick="selectVariant(this, 'Pedas Banget')" class="variant-btn bg-slate-800 border border-slate-700 text-slate-300 text-[11px] font-bold py-2 rounded-xl transition">Ekstra Pedas</button>
                        <button onclick="selectVariant(this, 'Tidak Pedas')" class="variant-btn bg-slate-800 border border-slate-700 text-slate-300 text-[11px] font-bold py-2 rounded-xl transition">Tidak Pedas</button>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center justify-between">
                        <span>Catatan Khusus untuk Dapur:</span>
                        <span class="text-[10px] text-slate-500 lowercase font-normal">(opsional)</span>
                    </label>
                    <textarea id="modal-notes" rows="2" placeholder="Contoh: Telur setengah matang, jangan pakai sawi, banyakin kuah..." class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-brand-500 resize-none"></textarea>
                </div>

                <div class="flex items-center justify-between gap-4 pt-2 border-t border-slate-800">
                    <div class="flex items-center bg-slate-950 border border-slate-800 rounded-xl p-1">
                        <button onclick="adjustModalQty(-1)" class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 text-white font-bold flex items-center justify-center active:scale-95 transition">-</button>
                        <span id="modal-qty" class="w-8 text-center font-extrabold text-sm text-white">1</span>
                        <button onclick="adjustModalQty(1)" class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 text-white font-bold flex items-center justify-center active:scale-95 transition">+</button>
                    </div>
                    <button onclick="confirmAddToCart()" class="flex-1 bg-gradient-to-r from-brand-500 to-indigo-600 hover:from-brand-600 hover:to-indigo-700 text-white font-extrabold text-xs py-3 rounded-xl shadow-lg active:scale-95 transition-all flex items-center justify-center gap-2">
                        <i class="fa-solid fa-basket-shopping"></i>
                        <span>Masukkan ke Keranjang</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- TOAST NOTIFIKASI -->
    <div id="toast-notif" class="fixed top-16 left-1/2 -translate-x-1/2 z-50 bg-emerald-500 text-slate-950 px-4 py-2 rounded-xl font-bold text-xs shadow-xl transition-all transform -translate-y-10 opacity-0 pointer-events-none flex items-center gap-2">
        <i class="fa-solid fa-circle-check"></i>
        <span id="toast-msg">Item berhasil ditambahkan!</span>
    </div>

    <script>
        // Data Produk Makanan langsung diambil dari Database MySQL via PHP JSON Encode
        const FOOD_PRODUCTS = <?= json_encode($food_products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        let activeCategory = 'all';
        let cartItems = JSON.parse(localStorage.getItem('cyberbite_cart') || '[]');
        let selectedProductForModal = null;
        let selectedVariant = 'Pedas Normal';
        let currentModalQty = 1;

        window.onload = function() {
            renderFoodProducts();
            updateCartBadges();
        };

        function renderFoodProducts() {
            const grid = document.getElementById('food-product-grid');
            if (!grid) return;
            const searchInput = document.getElementById('search-makanan');
            const search = searchInput ? searchInput.value.toLowerCase().trim() : '';
            grid.innerHTML = '';

            const filtered = FOOD_PRODUCTS.filter(p => {
                const matchCat = activeCategory === 'all' || p.category_slug === activeCategory;
                const matchSearch = p.name.toLowerCase().includes(search) || (p.description && p.description.toLowerCase().includes(search));
                return matchCat && matchSearch;
            });

            if (filtered.length === 0) {
                grid.innerHTML = `
                    <div class="col-span-full py-12 text-center text-slate-500">
                        <i class="fa-solid fa-utensils text-3xl mb-2 block text-slate-600"></i>
                        <p class="text-xs font-bold text-slate-400">Makanan tidak ditemukan</p>
                    </div>
                `;
                return;
            }

            filtered.forEach(p => {
                const card = document.createElement('div');
                card.className = "bg-slate-900 border border-slate-800/90 rounded-2xl overflow-hidden hover:border-brand-500/40 transition-all flex flex-col justify-between shadow-lg group";
                card.innerHTML = `
                    <div>
                        <div class="relative overflow-hidden h-28 bg-slate-800">
                            <img src="${p.image}" alt="${p.name}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                        </div>
                        <div class="p-3 space-y-1">
                            <h3 class="text-xs font-bold text-slate-100 line-clamp-2 leading-snug">${p.name}</h3>
                            <p class="text-[10px] text-slate-400 line-clamp-2">${p.description || ''}</p>
                        </div>
                    </div>
                    <div class="p-3 pt-0 flex items-center justify-between gap-1 mt-1">
                        <span class="text-xs font-extrabold text-emerald-400">${formatRupiah(parseFloat(p.price))}</span>
                        <button onclick="openDetailModal(${p.id})" class="bg-brand-600/20 hover:bg-brand-600 text-brand-300 hover:text-white border border-brand-500/30 text-[10px] font-bold px-2.5 py-1.5 rounded-xl flex items-center gap-1 transition-all active:scale-95">
                            <i class="fa-solid fa-plus"></i> Detail
                        </button>
                    </div>
                `;
                grid.appendChild(card);
            });
        }

        function selectFoodCategory(cat) {
            activeCategory = cat;
            document.querySelectorAll('.food-cat-btn').forEach(btn => {
                if (btn.dataset.cat === cat) {
                    btn.className = "food-cat-btn active px-4 py-2 rounded-xl text-xs font-bold whitespace-nowrap bg-brand-600 text-white shadow-md shadow-brand-600/30";
                } else {
                    btn.className = "food-cat-btn px-4 py-2 rounded-xl text-xs font-bold whitespace-nowrap bg-slate-950 text-slate-400 border border-slate-800 hover:text-white transition-all";
                }
            });
            renderFoodProducts();
        }

        function openDetailModal(id) {
            selectedProductForModal = FOOD_PRODUCTS.find(p => parseInt(p.id) === parseInt(id));
            if (!selectedProductForModal) return;

            document.getElementById('modal-img').src = selectedProductForModal.image;
            document.getElementById('modal-title').innerText = selectedProductForModal.name;
            document.getElementById('modal-desc').innerText = selectedProductForModal.description || '';
            document.getElementById('modal-price').innerText = formatRupiah(parseFloat(selectedProductForModal.price));
            document.getElementById('modal-cat').innerText = selectedProductForModal.category_slug;
            document.getElementById('modal-notes').value = '';
            
            currentModalQty = 1;
            document.getElementById('modal-qty').innerText = currentModalQty;

            const modal = document.getElementById('detail-modal');
            const card = document.getElementById('detail-modal-card');
            modal.classList.remove('opacity-0', 'pointer-events-none');
            card.classList.remove('scale-95');
        }

        function closeDetailModal() {
            const modal = document.getElementById('detail-modal');
            const card = document.getElementById('detail-modal-card');
            card.classList.add('scale-95');
            modal.classList.add('opacity-0', 'pointer-events-none');
        }

        function selectVariant(btn, variantName) {
            selectedVariant = variantName;
            document.querySelectorAll('.variant-btn').forEach(b => {
                b.className = "variant-btn bg-slate-800 border border-slate-700 text-slate-300 text-[11px] font-bold py-2 rounded-xl transition";
            });
            btn.className = "variant-btn active bg-brand-600 border border-brand-500 text-white text-[11px] font-bold py-2 rounded-xl transition";
        }

        function adjustModalQty(delta) {
            currentModalQty = Math.max(1, currentModalQty + delta);
            document.getElementById('modal-qty').innerText = currentModalQty;
        }

        function confirmAddToCart() {
            if (!selectedProductForModal) return;

            const notes = document.getElementById('modal-notes').value.trim();
            const cartItem = {
                id: selectedProductForModal.id,
                name: selectedProductForModal.name,
                price: parseFloat(selectedProductForModal.price),
                image: selectedProductForModal.image,
                variant: selectedVariant,
                notes: notes,
                qty: currentModalQty,
                type: 'makanan'
            };

            cartItems.push(cartItem);
            localStorage.setItem('cyberbite_cart', JSON.stringify(cartItems));

            updateCartBadges();
            closeDetailModal();
            showToast(`"${selectedProductForModal.name}" ditambahkan!`);
        }

        function openCartModal() {
            renderCartModalList();
            const modal = document.getElementById('cart-modal');
            const card = document.getElementById('cart-modal-card');
            modal.classList.remove('opacity-0', 'pointer-events-none');
            card.classList.remove('scale-95');
        }

        function closeCartModal() {
            const modal = document.getElementById('cart-modal');
            const card = document.getElementById('cart-modal-card');
            card.classList.add('scale-95');
            modal.classList.add('opacity-0', 'pointer-events-none');
        }

        function renderCartModalList() {
            const container = document.getElementById('cart-modal-items-container');
            const emptyState = document.getElementById('cart-modal-empty');
            const checkoutBtn = document.getElementById('btn-modal-checkout');
            const itemBadge = document.getElementById('cart-modal-item-badge');

            container.innerHTML = '';
            itemBadge.innerText = `${cartItems.length} Item`;

            if (cartItems.length === 0) {
                container.classList.add('hidden');
                emptyState.classList.remove('hidden');
                checkoutBtn.disabled = true;
                checkoutBtn.classList.add('opacity-50', 'cursor-not-allowed');
                document.getElementById('cart-modal-subtotal').innerText = 'Rp 0';
                document.getElementById('cart-modal-total').innerText = 'Rp 0';
                return;
            }

            container.classList.remove('hidden');
            emptyState.classList.add('hidden');
            checkoutBtn.disabled = false;
            checkoutBtn.classList.remove('opacity-50', 'cursor-not-allowed');

            let subtotal = 0;

            cartItems.forEach((item, index) => {
                const itemTotal = item.price * item.qty;
                subtotal += itemTotal;

                const typeBadge = item.type === 'minuman'
                    ? `<span class="bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 text-[9px] font-extrabold px-1.5 py-0.5 rounded uppercase">🥤 Minuman</span>`
                    : `<span class="bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[9px] font-extrabold px-1.5 py-0.5 rounded uppercase">🍜 Makanan</span>`;

                const notesHtml = item.notes && item.notes.trim() !== ''
                    ? `<p class="text-[10px] text-brand-300 italic bg-slate-950/80 px-2 py-0.5 rounded border border-slate-800 mt-1">"${item.notes}"</p>`
                    : '';

                const card = document.createElement('div');
                card.className = "bg-slate-950/60 border border-slate-800 rounded-xl p-3 flex items-center justify-between gap-3";
                card.innerHTML = `
                    <div class="flex items-center gap-3">
                        <img src="${item.image}" alt="${item.name}" class="w-14 h-14 rounded-lg object-cover bg-slate-800 border border-slate-800 shrink-0">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-1.5">
                                ${typeBadge}
                                <span class="text-[10px] text-slate-400 font-semibold">${item.variant}</span>
                            </div>
                            <h4 class="text-xs font-bold text-white line-clamp-1">${item.name}</h4>
                            <p class="text-xs font-extrabold text-emerald-400">${formatRupiah(item.price)}</p>
                            ${notesHtml}
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center bg-slate-900 border border-slate-800 rounded-lg p-0.5">
                            <button onclick="changeModalCartQty(${index}, -1)" class="w-6 h-6 rounded bg-slate-800 text-white text-xs font-bold flex items-center justify-center hover:bg-slate-700">-</button>
                            <span class="w-6 text-center text-xs font-extrabold">${item.qty}</span>
                            <button onclick="changeModalCartQty(${index}, 1)" class="w-6 h-6 rounded bg-slate-800 text-white text-xs font-bold flex items-center justify-center hover:bg-slate-700">+</button>
                        </div>
                        <button onclick="removeModalCartItem(${index})" title="Hapus" class="w-7 h-7 rounded-lg bg-rose-500/10 text-rose-400 border border-rose-500/20 flex items-center justify-center hover:bg-rose-500/20">
                            <i class="fa-solid fa-trash-can text-xs"></i>
                        </button>
                    </div>
                `;
                container.appendChild(card);
            });

            document.getElementById('cart-modal-subtotal').innerText = formatRupiah(subtotal);
            document.getElementById('cart-modal-total').innerText = formatRupiah(subtotal);
        }

        function changeModalCartQty(index, delta) {
            if (cartItems[index]) {
                cartItems[index].qty += delta;
                if (cartItems[index].qty <= 0) {
                    cartItems.splice(index, 1);
                }
                localStorage.setItem('cyberbite_cart', JSON.stringify(cartItems));
                renderCartModalList();
                updateCartBadges();
            }
        }

        function removeModalCartItem(index) {
            if (cartItems[index]) {
                cartItems.splice(index, 1);
                localStorage.setItem('cyberbite_cart', JSON.stringify(cartItems));
                renderCartModalList();
                updateCartBadges();
                showToast("Item dihapus dari keranjang");
            }
        }

        function clearCartFromModal() {
            if (cartItems.length === 0) return;
            cartItems = [];
            localStorage.setItem('cyberbite_cart', JSON.stringify(cartItems));
            renderCartModalList();
            updateCartBadges();
            showToast("Keranjang dikosongkan");
        }

        function proceedToPaymentFromModal() {
            if (cartItems.length === 0) return;
            window.location.href = 'pembayaran.php';
        }

        function updateCartBadges() {
            const totalCount = cartItems.length;
            const totalPrice = cartItems.reduce((sum, item) => sum + (item.price * item.qty), 0);

            document.getElementById('cart-header-badge').innerText = totalCount;
            document.getElementById('cart-count-badge').innerText = totalCount;
            document.getElementById('cart-total-price').innerText = formatRupiah(totalPrice);

            const floatingBar = document.getElementById('floating-cart');
            if (totalCount > 0) {
                floatingBar.classList.remove('translate-y-28', 'opacity-0', 'pointer-events-none');
            } else {
                floatingBar.classList.add('translate-y-28', 'opacity-0', 'pointer-events-none');
            }
        }

        function showToast(msg) {
            const toast = document.getElementById('toast-notif');
            document.getElementById('toast-msg').innerText = msg;
            toast.classList.remove('-translate-y-10', 'opacity-0', 'pointer-events-none');
            setTimeout(() => {
                toast.classList.add('-translate-y-10', 'opacity-0', 'pointer-events-none');
            }, 2500);
        }

        function formatRupiah(num) {
            return 'Rp ' + num.toLocaleString('id-ID');
        }
    </script>
</body>
</html>
