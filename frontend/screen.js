(() => {
    const app = document.getElementById('screen-app');
    const tournamentId = app.dataset.tournamentId;
    const apiUrl = new URL(window.location.pathname, window.location.origin);
    apiUrl.searchParams.set('data', '1');
    if (tournamentId) apiUrl.searchParams.set('tournament_id', tournamentId);

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    };

    const statusTitle = (status) => ({
        draft: 'Noch nicht ausgelost',
        in_progress: 'Turnier läuft',
        completed: 'Turnier beendet',
    }[status] || 'Turnier');

    function makeMatchCard(match, preview = false) {
        const card = element('article', `screen-match${preview ? ' screen-match-preview' : ''}`);
        const heading = element('div', 'screen-match-heading');
        heading.append(
            element('span', 'screen-match-bracket', match.stage_label || match.bracket),
            element('span', 'screen-match-state', preview ? 'Vorschau' : ({
                pending: 'Offen',
                completed: 'Beendet',
                bye: 'Freilos',
            }[match.status] || ''))
        );
        card.append(heading);

        if (match.payout_label) card.append(element('div', 'screen-payout-badge', match.payout_label));

        [
            { name: match.player_one_name || 'Freilos', score: match.score_one, id: match.player_one_id },
            { name: match.player_two_name || 'Freilos', score: match.score_two, id: match.player_two_id },
        ].forEach((player) => {
            const row = element('div', 'screen-player');
            if (!preview && match.winner_id && Number(match.winner_id) === Number(player.id)) {
                row.classList.add('screen-player-winner');
            }
            row.append(
                element('span', 'screen-player-name', player.name),
                element('strong', 'screen-score', preview || player.score === null ? '–' : player.score)
            );
            card.append(row);
        });

        if (match.board_label) card.append(element('div', 'screen-board', `Dartboard ${match.board_label}`));
        return card;
    }

    function makeRound(round) {
        const section = element('section', 'screen-round');
        section.append(element('h3', 'screen-section-title', `Runde ${round.number}`));
        const grid = element('div', 'screen-match-grid');
        round.matches.forEach((match) => grid.append(makeMatchCard(match)));
        section.append(grid);
        return section;
    }

    function makeStandings(standings, qualifiers, format) {
        const wrapper = element('section', 'screen-standings');
        wrapper.append(element('h2', 'screen-section-title', 'Tabelle'));
        const groups = element('div', 'screen-group-grid');

        Object.entries(standings).forEach(([groupName, players]) => {
            const isRoundRobin = format === 'round_robin';
            const group = element('section', 'screen-group');
            group.append(element('h3', 'screen-group-title', isRoundRobin ? 'Turniertabelle' : groupName));
            const table = document.createElement('table');
            table.className = 'screen-table';
            const thead = document.createElement('thead');
            const headRow = document.createElement('tr');
            ['#', 'Spieler', 'Sp', 'S', 'Diff.'].forEach((label) => headRow.append(element('th', '', label)));
            thead.append(headRow);
            const tbody = document.createElement('tbody');

            players.forEach((player, index) => {
                const row = document.createElement('tr');
                const isQualifier = !isRoundRobin && index < qualifiers;
                if (isQualifier) row.className = 'screen-qualifier';
                row.append(
                    element('td', '', index + 1),
                    element('th', '', `${player.name}${isQualifier ? ' · KO' : ''}`),
                    element('td', '', player.played),
                    element('td', '', player.wins),
                    element('td', '', Number(player.difference) > 0 ? `+${player.difference}` : player.difference)
                );
                tbody.append(row);
            });

            table.append(thead, tbody);
            group.append(table);
            groups.append(group);
        });
        wrapper.append(groups);
        return wrapper;
    }

    function makePayout(payout) {
        const wrapper = element('section', 'screen-payout');
        const heading = element('div', 'screen-payout-heading');
        heading.append(
            element('h2', 'screen-section-title', 'Geplante Ausschüttung'),
            element(
                'strong',
                'screen-payout-total',
                new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' })
                    .format((payout.pool_cents || 0) / 100)
            )
        );
        wrapper.append(heading);

        if (!payout.places?.length || Number(payout.percentage_basis_points) === 0) {
            wrapper.append(element('p', 'screen-payout-empty', 'Für dieses Turnier ist keine Ausschüttung festgelegt.'));
            return wrapper;
        }

        const list = element('ol', 'screen-payout-list');
        const currency = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
        payout.places.forEach((place) => {
            const row = element('li', 'screen-payout-place');
            row.append(
                element('span', 'screen-payout-rank', `${place.place}. Platz`),
                element('span', 'screen-payout-player', place.player_name || 'Noch offen'),
                element('strong', 'screen-payout-amount', currency.format((place.amount_cents || 0) / 100))
            );
            list.append(row);
        });
        wrapper.append(list);
        return wrapper;
    }

    function render(data) {
        app.replaceChildren();
        const tournament = data.tournament;
        if (!tournament) {
            const empty = element('section', 'screen-empty');
            empty.append(
                element('div', 'screen-brand', 'DARTBRACKET'),
                element('h1', '', 'Kein Turnier ausgewählt'),
                element('p', '', 'Wähle ein aktives Turnier in der Turnierverwaltung aus.')
            );
            app.append(empty);
            return;
        }

        const header = element('header', 'screen-header');
        const metaParts = [statusTitle(tournament.status)];
        if (tournament.event_date) {
            const date = new Date(`${tournament.event_date}T00:00:00`);
            metaParts.unshift(new Intl.DateTimeFormat('de-DE', { dateStyle: 'long' }).format(date));
        }
        header.append(
            element('div', 'screen-brand', 'DARTBRACKET  /  LIVE'),
            element('h1', 'screen-title', tournament.name),
            element('p', 'screen-subtitle', metaParts.join('  ·  ')),
            element('div', 'screen-live', 'LIVE')
        );

        const stats = element('section', 'screen-stats');
        [
            ['TEILNEHMER', tournament.participant_count],
            ['SPIELE GESPIELT', data.counts.played],
            ['NOCH OFFEN', data.counts.remaining],
        ].forEach(([label, value]) => {
            const stat = element('article', 'screen-stat');
            stat.append(element('span', '', label), element('strong', '', value));
            stats.append(stat);
        });

        const payout = makePayout(data.payout || { places: [], pool_cents: 0 });
        const main = element('div', 'screen-main');
        const bracket = element('section', 'screen-bracket');
        bracket.append(element('h2', 'screen-section-title', 'Turnierbaum & Spielplan'));
        if ((tournament.format === 'round_robin' || tournament.format === 'groups_ko')
            && Object.keys(data.standings || {}).length) {
            bracket.append(makeStandings(data.standings, tournament.qualifiers_per_group, tournament.format));
        }

        if (data.rounds.length) {
            data.rounds.forEach((round) => bracket.append(makeRound(round)));
        } else {
            bracket.append(element('p', 'screen-empty-note', 'Die Paarungen erscheinen nach der Auslosung.'));
        }

        const previewMatches = Array.isArray(data.upcoming_preview)
            ? []
            : (data.upcoming_preview?.matches || []);
        if (previewMatches.length) {
            const previewRound = element('section', 'screen-round screen-preview-round');
            previewRound.append(
                element('h3', 'screen-section-title', 'Nächste Runde · Vorschau'),
                element('p', 'screen-preview-note', 'Paarungen werden mit den Spielergebnissen festgelegt.')
            );
            const previewGrid = element('div', 'screen-match-grid');
            previewMatches.forEach((match) => previewGrid.append(makeMatchCard({
                ...match,
                status: 'pending',
                player_one_id: null,
                player_two_id: null,
                score_one: null,
                score_two: null,
            }, true)));
            previewRound.append(previewGrid);
            bracket.append(previewRound);
        }

        const upcoming = element('aside', 'screen-upcoming');
        upcoming.append(
            element('h2', 'screen-section-title', 'Anstehende Spiele'),
            element('p', 'screen-upcoming-count', `${data.counts.pending_now} aktuell angesetzt`)
        );
        const pendingMatches = data.rounds.flatMap((round) => round.matches)
            .filter((match) => match.status === 'pending');
        if (!pendingMatches.length) {
            upcoming.append(element('p', 'screen-empty-note', tournament.status === 'completed'
                ? 'Alle Spiele sind beendet.'
                : 'Zurzeit stehen keine Spiele an.'));
        } else {
            const upcomingList = element('div', 'screen-upcoming-list');
            pendingMatches.slice(0, 8).forEach((match) => {
                const item = element('article', 'screen-upcoming-match');
                item.append(
                    element('span', 'screen-upcoming-stage', match.stage_label || match.bracket),
                    element('strong', '', `${match.player_one_name || 'Noch offen'}  vs  ${match.player_two_name || 'Noch offen'}`)
                );
                if (match.board_label) item.append(element('span', 'screen-upcoming-board', `Dartboard ${match.board_label}`));
                upcomingList.append(item);
            });
            if (pendingMatches.length > 8) {
                upcomingList.append(element('p', 'screen-more-games', `und ${pendingMatches.length - 8} weitere Spiele`));
            }
            upcoming.append(upcomingList);
        }

        main.append(bracket, upcoming);
        const footer = element('footer', 'screen-footer');
        footer.append(
            element('span', '', `Aktualisiert ${new Intl.DateTimeFormat('de-DE', { timeStyle: 'medium' }).format(new Date(data.updated_at))}`),
            element('span', '', 'Automatische Aktualisierung alle 4 Sekunden')
        );
        app.append(header, stats, payout, main, footer);
    }

    let fetching = false;
    async function refresh() {
        if (fetching) return;
        fetching = true;
        try {
            const response = await fetch(apiUrl, { cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            render(await response.json());
        } catch (error) {
            console.error('Beameransicht konnte nicht aktualisiert werden:', error);
            if (!app.querySelector('.screen-error')) {
                app.append(element('p', 'screen-error', 'Live-Aktualisierung fehlgeschlagen. Verbindung wird erneut versucht …'));
            }
        } finally {
            fetching = false;
        }
    }

    refresh();
    window.setInterval(refresh, 4000);
})();
