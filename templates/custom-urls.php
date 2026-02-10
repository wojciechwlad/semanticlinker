<?php
/**
 * Admin template – SemanticLinker AI → Custom URLs
 *
 * Allows users to add external/custom URLs that will be included
 * in the semantic linking process.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$custom_urls  = SL_DB::get_all_custom_urls();
$custom_count = count( $custom_urls );
$max_custom   = SL_DB::get_max_custom_urls();

// Count URLs with embeddings
$with_embedding = 0;
foreach ( $custom_urls as $url ) {
	if ( ! empty( $url->embedding ) ) {
		$with_embedding++;
	}
}
?>
<div class="wrap sl-wrap">

	<!-- Page title -->
	<h1 class="sl-page-title">
		<span class="dashicons dashicons-admin-links"></span>
		SemanticLinker AI &#8212; Custom URLs
	</h1>

	<!-- Status bar -->
	<div class="sl-status-bar">
		<span class="sl-status-item">
			URL-e: <strong><span id="sl-custom-url-count"><?php echo esc_html( $custom_count ); ?></span> / <?php echo esc_html( $max_custom ); ?></strong>
		</span>
		<span class="sl-status-item">
			Z embeddingiem:
			<?php if ( $with_embedding === $custom_count && $custom_count > 0 ) : ?>
				<span class="sl-badge sl-badge-ok"><?php echo esc_html( $with_embedding ); ?></span>
			<?php elseif ( $with_embedding > 0 ) : ?>
				<span class="sl-badge sl-badge-warn"><?php echo esc_html( $with_embedding ); ?> / <?php echo esc_html( $custom_count ); ?></span>
			<?php else : ?>
				<span class="sl-badge sl-badge-warn">0</span>
			<?php endif; ?>
		</span>
	</div>

	<!-- Two-column layout -->
	<div class="sl-layout">
		<div class="sl-main">

			<!-- Add new URL form -->
			<div class="sl-card">
				<h2 class="sl-card-title">Dodaj nowy URL</h2>
				<div style="display: flex; flex-direction: column; gap: 12px;">
					<div>
						<label for="sl-custom-url" style="display: block; font-weight: 600; margin-bottom: 4px;">URL *</label>
						<input type="url" id="sl-custom-url" placeholder="https://example.com/page"
							   class="regular-text" style="width: 100%; max-width: 500px;" />
					</div>
					<div>
						<label for="sl-custom-title" style="display: block; font-weight: 600; margin-bottom: 4px;">Tytuł *</label>
						<input type="text" id="sl-custom-title" placeholder="Tytuł strony docelowej"
							   class="regular-text" style="width: 100%; max-width: 500px;" />
						<p class="description">Tytuł używany do dopasowania semantycznego (jak tytuły artykułów).</p>
					</div>
					<div>
						<label for="sl-custom-keywords" style="display: block; font-weight: 600; margin-bottom: 4px;">Słowa kluczowe (opcjonalne)</label>
						<textarea id="sl-custom-keywords" rows="2" placeholder="kredyt hipoteczny, mieszkanie, bank"
								  style="width: 100%; max-width: 500px;"></textarea>
						<p class="description">Dodatkowe słowa kluczowe poprawiające dopasowanie. Oddziel przecinkami.</p>
					</div>
					<div>
						<button type="button" id="sl-btn-add-custom-url" class="button button-primary"
							<?php echo $custom_count >= $max_custom ? 'disabled' : ''; ?>>
							Dodaj URL
						</button>
						<?php if ( $custom_count >= $max_custom ) : ?>
							<span style="color: #d63638; margin-left: 10px;">Osiągnięto limit URL-i</span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- List of existing custom URLs -->
			<div class="sl-card">
				<h2 class="sl-card-title">Lista Custom URL-i</h2>

				<?php if ( ! empty( $custom_urls ) ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width: 30%;">URL</th>
								<th style="width: 20%;">Tytuł</th>
								<th style="width: 25%;">Słowa kluczowe</th>
								<th style="width: 10%;">Status</th>
								<th style="width: 15%;">Akcje</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $custom_urls as $custom ) : ?>
								<tr data-custom-id="<?php echo esc_attr( $custom->ID ); ?>">
									<!-- View mode -->
									<td class="sl-custom-url-view">
										<a href="<?php echo esc_url( $custom->url ); ?>" target="_blank" rel="noopener"
										   style="word-break: break-all; font-size: 12px;">
											<?php echo esc_html( $custom->url ); ?>
										</a>
									</td>
									<td class="sl-custom-url-view">
										<?php echo esc_html( $custom->title ); ?>
									</td>
									<td class="sl-custom-url-view">
										<span style="color: #666; font-size: 12px;">
											<?php echo esc_html( mb_strlen( $custom->keywords ) > 50
												? mb_substr( $custom->keywords, 0, 50 ) . '...'
												: $custom->keywords ); ?>
										</span>
									</td>
									<td class="sl-custom-url-view">
										<?php if ( $custom->embedding ) : ?>
											<span class="sl-badge sl-badge-ok">Aktywny</span>
										<?php else : ?>
											<span class="sl-badge sl-badge-warn">Brak embeddingu</span>
										<?php endif; ?>
									</td>
									<td class="sl-custom-url-view">
										<button type="button" class="button button-small sl-btn-edit-custom-url">Edytuj</button>
										<button type="button" class="button button-small sl-btn-delete-custom-url"
												data-id="<?php echo esc_attr( $custom->ID ); ?>"
												style="color: #a00;">Usuń</button>
									</td>

									<!-- Edit mode (hidden by default) -->
									<td class="sl-custom-url-edit" style="display: none;">
										<input type="url" class="sl-edit-url" value="<?php echo esc_attr( $custom->url ); ?>"
											   style="width: 100%; font-size: 12px;" />
									</td>
									<td class="sl-custom-url-edit" style="display: none;">
										<input type="text" class="sl-edit-title" value="<?php echo esc_attr( $custom->title ); ?>"
											   style="width: 100%;" />
									</td>
									<td class="sl-custom-url-edit" style="display: none;">
										<textarea class="sl-edit-keywords" rows="2"
												  style="width: 100%; font-size: 11px;"><?php echo esc_textarea( $custom->keywords ); ?></textarea>
									</td>
									<td class="sl-custom-url-edit" style="display: none;">
										<!-- Status not editable -->
									</td>
									<td class="sl-custom-url-edit" style="display: none;">
										<button type="button" class="button button-small button-primary sl-btn-save-custom-url"
												data-id="<?php echo esc_attr( $custom->ID ); ?>">Zapisz</button>
										<button type="button" class="button button-small sl-btn-cancel-edit">Anuluj</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p style="color: #666; font-style: italic;">Brak custom URL-i. Dodaj pierwszy powyżej.</p>
				<?php endif; ?>
			</div>

		</div><!-- /.sl-main -->

		<!-- Sidebar -->
		<div class="sl-sidebar">

			<!-- Custom URL Threshold setting -->
			<div class="sl-card">
				<h2 class="sl-card-title">Próg dopasowania</h2>
				<p class="description" style="margin-top: 0;">
					Minimalny próg podobieństwa dla custom URL-i. Niższy próg = więcej linków.
				</p>
				<?php $custom_threshold = (float) SL_Settings::get( 'custom_url_threshold', 0.65 ); ?>
				<div style="display: flex; align-items: center; gap: 12px; margin-top: 12px;">
					<input type="range" id="sl-custom-url-threshold" min="0.20" max="0.90" step="0.05"
						   value="<?php echo esc_attr( $custom_threshold ); ?>"
						   style="flex: 1; max-width: 200px;" />
					<span id="sl-custom-url-threshold-value" style="font-weight: 600; min-width: 45px;">
						<?php echo esc_html( number_format( $custom_threshold, 2 ) ); ?>
					</span>
				</div>
				<div style="display: flex; justify-content: space-between; font-size: 11px; color: #666; max-width: 200px; margin-top: 4px;">
					<span>0.20</span>
					<span>0.90</span>
				</div>
				<button type="button" id="sl-btn-save-threshold" class="button button-secondary" style="margin-top: 12px;">
					Zapisz próg
				</button>
				<span id="sl-threshold-saved-msg" style="color: #00a32a; margin-left: 10px; display: none;">✓ Zapisano</span>
			</div>

			<!-- Info box -->
			<div class="sl-card" style="background: #e7f5ff; border-color: #74c0fc;">
				<h2 class="sl-card-title" style="color: #1971c2; border-bottom-color: #74c0fc;">Jak to działa?</h2>
				<ul style="font-size: 13px; color: #1864ab; margin: 0; padding-left: 18px; line-height: 1.6;">
					<li>Dodaj zewnętrzne URL-e (landing page, strony partnerów, itp.)</li>
					<li>Plugin automatycznie generuje embedding na podstawie tytułu i słów kluczowych</li>
					<li>Custom URL-e uczestniczą w procesie linkowania jak zwykłe artykuły</li>
					<li>Linki do custom URL-i <strong>nie są filtrowane</strong> przez filtr AI Gemini</li>
					<li>Limit: max <?php echo esc_html( $max_custom ); ?> URL-i</li>
				</ul>
			</div>

			<!-- Warning box -->
			<div class="sl-card" style="background: #fff8e6; border-color: #f0c36d;">
				<h2 class="sl-card-title" style="color: #856404; border-bottom-color: #f0c36d;">Wskazówki</h2>
				<ul style="font-size: 13px; color: #664d03; margin: 0; padding-left: 18px; line-height: 1.6;">
					<li>Używaj opisowych tytułów (jak tytuły artykułów)</li>
					<li>Dodaj słowa kluczowe, które powinny triggerować linkowanie</li>
					<li>Po dodaniu/edycji URL-a embedding jest generowany automatycznie</li>
					<li>Uruchom reindeksację, aby custom URL-e zostały uwzględnione w linkach</li>
				</ul>
			</div>

		</div><!-- /.sl-sidebar -->
	</div><!-- /.sl-layout -->

</div><!-- .wrap -->
