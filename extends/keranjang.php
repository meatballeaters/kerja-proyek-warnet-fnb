<!-- MODAL KERANJANG BELANJA -->
    <div id="cart-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-slate-900 border border-slate-800 w-full max-w-4xl rounded-2xl overflow-hidden shadow-2xl transform scale-95 transition-transform duration-300 max-h-[90vh] flex flex-col" id="cart-modal-card">
            <div class="px-6 py-4 bg-slate-900/90 border-b border-slate-800 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <div class="bg-brand-500/10 border border-brand-500/20 text-brand-400 p-2.5 rounded-xl">
                        <i class="fa-solid fa-basket-shopping text-base"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-extrabold text-white">Keranjang Pesanan</h2>
                            <span id="cart-modal-item-badge" class="bg-brand-500/20 text-brand-400 border border-brand-500/30 text-[10px] font-extrabold px-2 py-0.5 rounded-md font-mono">0 Item</span>
                        </div>
                        <p class="text-[11px] text-slate-400">Pengantaran Langsung Ke: <b class="text-brand-400"><?= htmlspecialchars($active_pc['pc_name']) ?></b> (<?= htmlspecialchars($active_pc['active_voucher']) ?>)</p>
                    </div>
                </div>
                <button onclick="closeCartModal()" class="bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white w-8 h-8 rounded-xl flex items-center justify-center border border-slate-700 transition-all">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <div class="p-6 overflow-y-auto flex-1 grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-3">
                    <div id="cart-modal-items-container" class="space-y-3"></div>
                    <div id="cart-modal-empty" class="hidden bg-slate-950/60 border border-slate-800/80 rounded-2xl p-10 text-center space-y-3">
                        <i class="fa-solid fa-basket-shopping text-3xl text-slate-700"></i>
                        <p class="text-xs font-bold text-slate-300">Keranjang Belanja Masih Kosong</p>
                        <p class="text-[11px] text-slate-500 max-w-xs mx-auto">Belum menambahkan makanan atau minuman ke keranjang pesanan <?= htmlspecialchars($active_pc['pc_name']) ?>.</p>
                        <div class="flex justify-center gap-2 pt-2">
                            <button onclick="closeCartModal()" class="bg-brand-600 text-white font-bold text-xs px-3.5 py-2 rounded-xl">Pilih Makanan</button>
                            <a href="index-2.php" class="bg-slate-800 border border-slate-700 text-cyan-400 font-bold text-xs px-3.5 py-2 rounded-xl">Pilih Minuman</a>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-1 space-y-4">
                    <div class="bg-slate-950/80 border border-slate-800 p-4 rounded-2xl space-y-4 sticky top-0">
                        <h3 class="text-xs font-extrabold text-white uppercase tracking-wider border-b border-slate-800 pb-2 flex justify-between items-center">
                            <span>Ringkasan Pesanan</span>
                            <i class="fa-solid fa-receipt text-slate-500"></i>
                        </h3>
                        <div class="space-y-2.5 text-xs">
                            <div class="flex justify-between text-slate-400">
                                <span>Subtotal Produk</span>
                                <span id="cart-modal-subtotal" class="font-bold text-slate-200">Rp 0</span>
                            </div>
                            <div class="flex justify-between text-slate-400">
                                <span>Ongkir Meja PC</span>
                                <span class="text-emerald-400 font-bold uppercase text-[9px] bg-emerald-500/10 px-1.5 py-0.5 rounded border border-emerald-500/20">Gratis</span>
                            </div>
                            <div class="border-t border-slate-800 pt-2 flex justify-between items-center text-sm font-extrabold text-white">
                                <span>Total Bayar</span>
                                <span id="cart-modal-total" class="text-emerald-400 text-base">Rp 0</span>
                            </div>
                        </div>
                        <button onclick="proceedToPaymentFromModal()" id="btn-modal-checkout" class="w-full bg-gradient-to-r from-brand-500 to-indigo-600 hover:from-brand-600 hover:to-indigo-700 text-white font-bold text-xs py-3 rounded-xl shadow-lg active:scale-95 transition-all flex items-center justify-center gap-2">
                            <span>Pilih Metode Pembayaran</span>
                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </button>
                        <div class="flex items-center justify-between text-[10px] pt-1">
                            <a href="index-2.php" class="text-cyan-400 hover:underline font-bold flex items-center gap-1">
                                <i class="fa-solid fa-plus text-[9px]"></i> Tambah Minuman
                            </a>
                            <button onclick="clearCartFromModal()" class="text-rose-400 hover:text-rose-300 font-semibold flex items-center gap-1">
                                <i class="fa-solid fa-trash-can text-[9px]"></i> Kosongkan
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>