(() => {
    const app = document.getElementById('tree-app');
    const tournamentId = app.dataset.tournamentId;
    const apiUrl = new URL('screen.php', window.location.href);
    apiUrl.searchParams.set('data', '1');
    if (tournamentId) apiUrl.searchParams.set('tournament_id', tournamentId);

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    };

    const statusLabel = (status) => ({
        draft: 'Entwurf',
        in_progress: 'Turnier läuft',
        completed: 'Beendet',
    }[status] || 'Turnier');

    function makeMatch(match, index, lane) {
        const node = element('article', `tree-match tree-match-${match.status || 'pending'}`);
        node.dataset.matchId = match.id || `${lane.name}-${match.round_number}-${index}`;
        const heading = element('div', 'tree-match-heading');
        heading.append(
            element('span', 'tree-match-label', match.stage_label || `Spiel ${match.match_number || index + 1}`),
            element('span', 'tree-match-status', ({
                pending: 'Offen',
                completed: 'Beendet',
                bye: 'Freilos',
            }[match.status] || 'Vorschau'))
        );
        node.append(heading);

        [
            { name: match.player_one_name || 'Noch offen', score: match.score_one, id: match.player_one_id },
            { name: match.player_two_name || 'Noch offen', score: match.score_two, id: match.player_two_id },
        ].forEach((player) => {
            const row = element('div', 'tree-player');
            if (match.winner_id && Number(match.winner_id) === Number(player.id)) {
                row.classList.add('tree-player-winner');
            }
            row.append(
                element('span', 'tree-player-name', player.name),
                element('strong', 'tree-score', player.score === null || player.score === undefined ? '–' : player.score)
            );
            node.append(row);
        });

        if (match.board_label) node.append(element('div', 'tree-board', `Dartboard ${match.board_label}`));
        if (match.payout_label) node.append(element('div', 'tree-payout', match.payout_label));
        return node;
    }

    function buildLanes(rounds) {
        const matches = rounds.flatMap((round) => round.matches.map((match) => ({
            ...match,
            round_number: round.number,
        })));
        const lanes = new Map();
        matches.forEach((match) => {
            const laneName = match.bracket || 'Turnier';
            if (!lanes.has(laneName)) lanes.set(laneName, new Map());
            const laneRounds = lanes.get(laneName);
            if (!laneRounds.has(match.round_number)) laneRounds.set(match.round_number, []);
            laneRounds.get(match.round_number).push(match);
        });

        return [...lanes.entries()].map(([name, laneRounds]) => ({
            name,
            rounds: [...laneRounds.entries()]
                .sort(([left], [right]) => Number(left) - Number(right))
                .map(([number, roundMatches]) => ({
                    number: Number(number),
                    matches: roundMatches.sort((left, right) => left.match_number - right.match_number),
                })),
            connects: name !== 'Rundenturnier' && !name.startsWith('Gruppe '),
        }));
    }

    function makeLane(lane) {
        const section = element('section', 'tree-lane');
        section.dataset.connects = lane.connects ? 'true' : 'false';
        const title = element('h2', 'tree-lane-title', lane.name);
        const canvas = element('div', 'tree-canvas');
        const columns = [];
        const maxMatches = Math.max(...lane.rounds.map((round) => round.matches.length), 1);
        const rowHeight = 144;
        const cardHeight = 116;
        const canvasHeight = Math.max(220, maxMatches * rowHeight);
        canvas.style.height = `${canvasHeight}px`;

        lane.rounds.forEach((round, columnIndex) => {
            const column = element('div', 'tree-round-column');
            column.style.height = `${canvasHeight}px`;
            column.append(element('h3', 'tree-round-title', `Runde ${round.number}`));
            const nodes = [];
            round.matches.forEach((match, matchIndex) => {
                const card = makeMatch(match, matchIndex, lane);
                const center = ((matchIndex + 0.5) / round.matches.length) * canvasHeight;
                card.style.top = `${Math.max(50, center - cardHeight / 2)}px`;
                column.append(card);
                nodes.push(card);
            });
            columns.push({ round, nodes, columnIndex });
            canvas.append(column);
        });

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.classList.add('tree-connectors');
        svg.setAttribute('aria-hidden', 'true');
        canvas.prepend(svg);
        section.append(title, canvas);
        if (!lane.connects) {
            section.append(element('p', 'tree-lane-note', lane.name === 'Rundenturnier' || lane.name.startsWith('Gruppe ')
                ? 'Spielplan der Gruppenphase – alle Paarungen sind Teil derselben Tabelle.'
                : 'Begegnungen werden nach Turnierphase sortiert angezeigt.'));
        }

        return { section, canvas, svg, columns, canvasHeight, cardHeight, connects: lane.connects };
    }

    function drawConnections(lane) {
        lane.svg.replaceChildren();
        if (!lane.connects || lane.columns.length < 2) return;

        const canvasBounds = lane.canvas.getBoundingClientRect();
        lane.svg.setAttribute('width', String(lane.canvas.scrollWidth));
        lane.svg.setAttribute('height', String(lane.canvas.scrollHeight));
        lane.svg.setAttribute('viewBox', `0 0 ${lane.canvas.scrollWidth} ${lane.canvas.scrollHeight}`);

        lane.columns.slice(0, -1).forEach((column, columnIndex) => {
            const nextColumn = lane.columns[columnIndex + 1];
            if (nextColumn.round.number !== column.round.number + 1) return;

            column.nodes.forEach((source, sourceIndex) => {
                const target = nextColumn.nodes[Math.floor(sourceIndex / 2)];
                if (!target) return;
                const sourceBounds = source.getBoundingClientRect();
                const targetBounds = target.getBoundingClientRect();
                const startX = sourceBounds.right - canvasBounds.left + lane.canvas.scrollLeft;
                const startY = sourceBounds.top + sourceBounds.height / 2 - canvasBounds.top + lane.canvas.scrollTop;
                const endX = targetBounds.left - canvasBounds.left + lane.canvas.scrollLeft;
                const endY = targetBounds.top + targetBounds.height / 2 - canvasBounds.top + lane.canvas.scrollTop;
                const bendX = startX + (endX - startX) / 2;
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', `M ${startX} ${startY} H ${bendX} V ${endY} H ${endX}`);
                path.setAttribute('class', 'tree-connector-line');
                lane.svg.append(path);
            });
        });
    }

    function render(data) {
        app.replaceChildren();
        if (!data.tournament) {
            app.append(element('section', 'tree-empty', 'Kein aktives Turnier ausgewählt.'));
            return;
        }

        const tournament = data.tournament;
        const header = element('header', 'tree-header');
        const titleBlock = element('div', '');
        titleBlock.append(
            element('div', 'tree-brand', 'DARTBRACKET  /  TURNIERBAUM'),
            element('h1', 'tree-title', tournament.name),
            element('p', 'tree-subtitle', `${statusLabel(tournament.status)} · ${data.counts.played} gespielt · ${data.counts.remaining} offen`)
        );
        const headerActions = element('div', 'tree-header-actions');
        const back = element('a', 'tree-back', '← Zur Turnierverwaltung');
        back.href = `index.php?tournament_id=${encodeURIComponent(tournament.id)}`;
        headerActions.append(element('span', 'tree-live', 'LIVE'), back);
        header.append(titleBlock, headerActions);

        const graph = element('section', 'tree-graph');
        if (!data.rounds.length) {
            graph.append(element('p', 'tree-empty', 'Der Turnierbaum erscheint nach der Auslosung.'));
        } else {
            const lanes = buildLanes(data.rounds).map(makeLane);
            lanes.forEach((lane) => graph.append(lane.section));
            requestAnimationFrame(() => lanes.forEach(drawConnections));
            window.setTimeout(() => lanes.forEach(drawConnections), 100);
        }

        const footer = element('footer', 'tree-footer', 'Aktualisierung alle 4 Sekunden');
        app.append(header, graph, footer);
    }

    let fetching = false;
    async function refresh() {
        if (fetching) return;
        fetching = true;
        try {
            const response = await fetch(apiUrl, { cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            render(data);
        } catch (error) {
            console.error('Turnierbaum konnte nicht aktualisiert werden:', error);
            if (!app.querySelector('.tree-error')) {
                app.append(element('p', 'tree-error', 'Live-Aktualisierung fehlgeschlagen. Verbindung wird erneut versucht …'));
            }
        } finally {
            fetching = false;
        }
    }

    window.addEventListener('resize', () => {
        document.querySelectorAll('.tree-lane').forEach((section) => {
            const canvas = section.querySelector('.tree-canvas');
            const svg = section.querySelector('.tree-connectors');
            if (canvas && svg) {
                svg.setAttribute('width', String(canvas.scrollWidth));
                svg.setAttribute('height', String(canvas.scrollHeight));
            }
        });
    });

    refresh();
    window.setInterval(refresh, 4000);
})();
