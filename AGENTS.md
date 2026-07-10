# AGENTS.md

## Cel projektu

MVP aplikacji do generycznego planowania zasobów.
Pierwszy przypadek użycia: miesięczny grafik pracowników medycznych.
Docelowo ten sam silnik ma obsługiwać inne domeny, np. planowanie produkcji.

## Stack

- Backend: Laravel, MariaDB
- Frontend: React, TypeScript, Inertia.js
- UI: Tailwind CSS, Tailwind UI-style components, Headless UI
- Queue/cache: Redis, Laravel Queue
- Uruchamianie lokalne: `docker compose up -d`

## Źródła danych

Katalog `source/` zawiera dane źródłowe i referencyjne.
Przed implementacją seedów, importerów lub założeń zawsze sprawdź `source/`.
Załączony grafik JPG traktuj jako źródło referencyjne dla danych demo.
Dane odczytane z JPG zapisz deterministycznie do JSON/CSV w `source/`, a seedery mają korzystać z tych plików, nie z wartości zakodowanych bezpośrednio w seederze.

## Nazewnictwo domenowe

Używaj nazw generycznych:

- `Resource` - zasób, na start pracownik
- `ResourceGroup` - grupa/rola zasobu, np. położna, pielęgniarka, operator
- `Skill` - umiejętność/uprawnienie
- `PlanningUnit` - jednostka planistyczna, np. oddział, sala, linia produkcyjna
- `ShiftTemplate` - szablon zmiany
- `DemandSlot` - konkretne zapotrzebowanie do obsadzenia
- `Assignment` - przypisanie zasobu do slotu
- `Absence` - absencja wyjątkowa
- `AvailabilityRule` - stała dostępność/niedostępność
- `PlanningRun` - uruchomienie generatora

W kodzie, bazie i typach TS używaj języka angielskiego.
W UI używaj języka polskiego.

## Silnik planowania

Silnik ma być oddzielony od Laravel/Eloquent.
Core solvera pracuje na DTO i kontraktach.
Eloquent może występować tylko w warstwie adapterów/fabryk/persystencji.

Główny solver MVP: constraint-aware genetic algorithm.
Nie implementuj losowego generatora udającego algorytm genetyczny.
Wymagane elementy: populacja, selekcja, crossover, mutacja, elitism, repair step, scoring/funkcja celu, deterministyczny seed.

Scoring/funkcja celu musi być wymienna przez interfejsy, żeby w innej aplikacji można było wstrzyknąć inną implementację.

## Reguły

Twarde ograniczenia nie mogą być łamane przez poprawne przypisania.
Jeżeli pełne pokrycie zapotrzebowania jest niemożliwe, slot może zostać nieobsadzony, ale wynik musi zawierać powód i karę scoringową.
Miękkie ograniczenia wpływają na wynik, ale nie blokują zapisu grafiku.

Czasy przechowuj i licz w minutach, nie na floatach.
Nie hardcoduj polskiego kodeksu pracy w silniku. Limity muszą być konfigurowalne.

## UI

Buduj współdzielone komponenty React/TypeScript.
Preferuj Headless UI dla modali, listboxów, menu i dialogów.
Widok grafiku ma przypominać miesięczną tabelę z JPG: dni jako kolumny, jednostki/zmiany jako wiersze, absencje jako dodatkowa warstwa informacji.

## Jakość

Dodawaj testy dla silnika, scoringu, constraintów, seedów z JPG oraz głównych feature flow.
Po zmianach uruchom testy backendu i build frontendu.
Dokumentuj istotne założenia w `IMPLEMENTATION_NOTES.md`.
