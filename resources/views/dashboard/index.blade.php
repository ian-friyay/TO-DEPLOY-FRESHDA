<!-- ============ DASHBOARD ============ -->
          <section id="dashboard-section" class="page-section">
            <div class="page-header">
              <h1 data-i18n="page.dashboard.title">Dashboard</h1>
              <p class="text-muted">Welcome back, {{ $user->name }} · {{ $user->roleLabel() }}</p>
              <div class="page-header-actions">
                <div class="filter-chip" style="cursor: default">
                  <i class="ri-calendar-line"></i> Today
                  <span class="filter-chip-date" id="todayDate"
                    >{{ now()->format('D, M j, Y') }}</span
                  >
                </div>
              </div>
            </div>

            <div class="stats-grid">
              @include('dashboard.partials.stat-card', [
                  'variant' => 'total',
                  'icon' => 'ri-history-line',
                  'label' => 'Total Scans',
                  'value' => $stats['total'],
                  'valueAttrs' => ['id' => 'statTotal'],
                  'sub' => '+'.$stats['week'].' this week',
              ])
              @include('dashboard.partials.stat-card', [
                  'variant' => 'fresh',
                  'icon' => 'ri-checkbox-circle-line',
                  'label' => 'Fresh',
                  'value' => $stats['fresh'],
                  'valueAttrs' => ['id' => 'statFresh'],
                  'sub' => ($stats['total'] ? round(($stats['fresh'] / $stats['total']) * 100, 1) : 0).'% of total',
              ])
              @include('dashboard.partials.stat-card', [
                  'variant' => 'moderate',
                  'icon' => 'ri-error-warning-line',
                  'label' => 'Moderate',
                  'value' => $stats['moderate'],
                  'valueAttrs' => ['id' => 'statModerate'],
                  'sub' => ($stats['total'] ? round(($stats['moderate'] / $stats['total']) * 100, 1) : 0).'% of total',
              ])
              @include('dashboard.partials.stat-card', [
                  'variant' => 'notfresh',
                  'icon' => 'ri-close-circle-line',
                  'label' => 'Not Fresh',
                  'value' => $stats['not_fresh'],
                  'valueAttrs' => ['id' => 'statNotFresh'],
                  'sub' => ($stats['total'] ? round(($stats['not_fresh'] / $stats['total']) * 100, 1) : 0).'% of total',
              ])
            </div>

            <div class="grid-2">
              <div class="card">
                <div class="card-header">
                  <span class="card-title"
                    ><i class="ri-history-line"></i> Recent Scans</span
                  >
                  <a class="view-all" data-page="history"
                    >View All <i class="ri-arrow-right-s-line"></i
                  ></a>
                </div>
                <div class="scan-list" id="recentScansList">
                  @forelse ($recentScans as $scan)
                    <div class="scan-item">
                      <div class="scan-thumb {{ $scan->freshnessClass() }}">
                        <i class="ri-image-line"></i>
                      </div>
                      <div class="scan-info">
                        <div class="scan-name">{{ $scan->species }}</div>
                        <div class="scan-meta">
                          <span>{{ $scan->freshnessLabel() }}</span><span class="sep">•</span
                          ><span>{{ $scan->created_at->diffForHumans() }}</span>
                        </div>
                      </div>
                      <span class="badge {{ $scan->freshnessClass() }}"
                        >{{ $scan->freshness_percentage }}%</span
                      >
                    </div>
                  @empty
                    <p class="text-muted" style="text-align:center; padding:1.25rem 0">
                      No scans yet. Scan your first fish!
                    </p>
                  @endforelse
                </div>
              </div>

              <div class="flex-col gap-2">
                <div class="card">
                  <div class="card-header">
                    <span class="card-title"
                      ><i class="ri-sparkling-line"></i> Quick Actions</span
                    >
                  </div>
                  <div class="quick-actions">
                    <a class="action-btn" data-page="scan"
                      ><i class="ri-camera-lens-line"></i> Scan Fish</a
                    >
                    @if ($user->isVendor())
                      <a class="action-btn" data-page="deliveries"
                        ><i class="ri-truck-line"></i> Deliveries</a
                      >
                    @endif
                    <a class="action-btn" data-page="notifications"
                      ><i class="ri-notification-3-line"></i> Notifications</a
                    >
                    @if ($user->isVendor())
                      <a class="action-btn" data-page="suppliers"
                        ><i class="ri-team-line"></i> Suppliers</a
                      >
                    @endif
                  </div>
                </div>

                @if ($user->isVendor())
                  <div class="card">
                    <div class="card-header">
                      <span class="card-title"
                        ><i class="ri-store-2-line"></i> Inventory Summary</span
                      >
                      <span class="badge vendor">Vendor</span>
                    </div>
                    <div>
                      @forelse ($inventorySummary['items'] as $item)
                        <div class="inventory-row">
                          <span class="name">{{ $item->name }}</span
                          ><span class="value">{{ $item->quantity }} kg</span>
                        </div>
                      @empty
                        <p class="text-muted" style="text-align:center; padding:0.75rem 0">
                          No inventory yet.
                        </p>
                      @endforelse
                      <div class="inventory-total">
                        <span class="name">Total Items</span>
                        <span class="value">{{ $inventorySummary['total'] }}</span>
                      </div>
                    </div>
                  </div>
                @endif
              </div>
            </div>

            <div class="card">
              @php
                  $aiServiceConfigured = filled(config('roboflow.api_key')) || filled(config('ai.url'));
                  $aiServiceLabel = filled(config('roboflow.api_key'))
                      ? 'Roboflow workflow configured'
                      : (filled(config('ai.url')) ? 'AI model service configured' : 'AI service not configured');
              @endphp
              <div class="ai-status">
                <div class="ai-status-left">
                  <div class="ai-icon"><i class="ri-cpu-line"></i></div>
                  <div class="ai-meta">
                    <strong>AI Service</strong>
                    <span>{{ $aiServiceLabel }}</span>
                  </div>
                </div>
                @if ($aiServiceConfigured)
                  <span class="badge ready"><span class="status-dot"></span> Configured</span>
                @else
                  <span class="badge"><span class="status-dot"></span> Not Configured</span>
                @endif
              </div>
            </div>
          </section>