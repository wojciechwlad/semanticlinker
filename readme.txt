=== SemanticLinker AI ===
Contributors: antigravity
Tags: internal linking, semantic links, AI, embeddings, SEO
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatyczne linkowanie wewnętrzne oparte na semantycznym dopasowaniu treści z wykorzystaniem Google Gemini Embeddings.

== Description ==

SemanticLinker AI to plugin WordPress, który automatycznie tworzy linki wewnętrzne między artykułami na podstawie semantycznego podobieństwa treści. Wykorzystuje API Google Gemini do generowania embeddingów i dopasowywania fraz anchor do odpowiednich artykułów docelowych.

**Główne funkcje:**

* Automatyczne wykrywanie fraz anchor w treści artykułów
* Semantyczne dopasowanie do artykułów docelowych
* Opcjonalny filtr AI weryfikujący jakość linków
* Custom URLs - linkowanie do zewnętrznych stron
* Klasteryzacja linków według URL docelowego
* Konfigurowalny próg podobieństwa
* Obsługa różnych typów postów
* Panel zarządzania linkami z możliwością odrzucania/przywracania

== Installation ==

1. Wgraj folder `semanticlinker-ai` do katalogu `/wp-content/plugins/`
2. Aktywuj plugin w panelu WordPress
3. Przejdź do SemanticLinker AI → Ustawienia
4. Wprowadź klucz API Google Gemini
5. Skonfiguruj ustawienia i uruchom indeksację

== Changelog ==

= 1.1.0 =
* Dodano: Custom URLs - możliwość linkowania do zewnętrznych stron
* Dodano: Limit linków per URL (klaster)
* Dodano: Sortowanie klastrów według najwyższego score
* Poprawiono: Wydajność przy dużej liczbie postów (cache zapytań DB)
* Poprawiono: Ukryty panel Debug Logs (rozwijany na żądanie)
* Zmieniono: Domyślny próg dla Custom URLs na 0.65

= 1.0.0 =
* Pierwsza wersja publiczna
* Automatyczne linkowanie wewnętrzne
* Integracja z Google Gemini Embeddings
* Panel zarządzania linkami
* Filtr AI Gemini

== Frequently Asked Questions ==

= Czy potrzebuję klucza API Google? =

Tak, plugin wymaga klucza API Google Gemini do generowania embeddingów i opcjonalnego filtrowania linków.

= Ile postów może obsłużyć plugin? =

Plugin jest zoptymalizowany do pracy z setkami postów. Przy bardzo dużych ilościach (1000+) zalecane jest uruchamianie indeksacji w mniejszych partiach.

= Czy mogę linkować do zewnętrznych stron? =

Tak, od wersji 1.1.0 dostępna jest funkcja Custom URLs pozwalająca na linkowanie do dowolnych zewnętrznych URL-i.

== Upgrade Notice ==

= 1.1.0 =
Nowa funkcja Custom URLs, poprawiona wydajność i nowe opcje konfiguracji.
