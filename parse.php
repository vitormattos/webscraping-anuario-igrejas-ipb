<?php
require 'vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;

$db = new SQLite3('igrejas.db');
$db->exec("CREATE TABLE IF NOT EXISTS igrejas (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	nome TEXT,
	presbiterio TEXT,
	endereco TEXT,
	cep TEXT,
	tel TEXT,
	email TEXT,
	website TEXT,
	municipio TEXT,
	uf TEXT,
	UNIQUE(nome, presbiterio, municipio, uf) ON CONFLICT IGNORE
)");

$db->exec("CREATE TABLE IF NOT EXISTS pastores (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	nome TEXT,
	tel TEXT,
	cel TEXT,
	email TEXT,
	UNIQUE(nome, tel, cel, email) ON CONFLICT IGNORE
)");

$db->exec("CREATE TABLE IF NOT EXISTS igreja_pastor (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	igreja_id INTEGER NOT NULL,
	pastor_id INTEGER NOT NULL,
	FOREIGN KEY (igreja_id) REFERENCES igrejas(id) ON DELETE CASCADE,
	FOREIGN KEY (pastor_id) REFERENCES pastores(id) ON DELETE CASCADE
)");

function extrairBlocosIgrejaPastor(string $html): void {
	$crawler = new Crawler($html);

	$atual = 0;
	$igrejas = $crawler->filter('div[style^="font-family: Helvetica, Arial; background-color: rgba(0,0,0,0.05);"]');
	$atual = 0;

	// Cada bloco contém uma igreja e, opcionalmente, um pastor
	$crawler->filter('div[style^="font-family: Helvetica, Arial; background-color: rgba(0,0,0,0.05);"]')
		->each(function (Crawler $blocoPai) use (&$atual, &$igrejas) {
			$divs = $blocoPai->filter('div');
			if ($divs->count() === 0) return;
			$igreja = parseIgreja($divs);
			$igreja['id'] = salvarRegistro($GLOBALS['db'], 'igrejas', $igreja, ['nome', 'presbiterio', 'municipio', 'uf']);

			$pastor = parsePastor($divs);
			if ($pastor) {
				$pastor['id'] = salvarRegistro($GLOBALS['db'], 'pastores', $pastor, ['nome', 'tel', 'cel', 'email']);
				salvarRegistro($GLOBALS['db'], 'igreja_pastor', [
					'igreja_id' => $igreja['id'],
					'pastor_id' => $pastor['id'],
				], ['igreja_id', 'pastor_id']);
			}
			$atual++;
			echo "\r$atual de {$igrejas->count()} blocos processados";
		});
	echo "\nProcessamento concluído.\n";
}

function salvarRegistro(SQLite3 $db, string $tabela, array $dados, array $chavesPrimarias): int {
	// Monta a query de verificação de existência
	$where = [];
	foreach ($chavesPrimarias as $chave) {
		$where[] = "$chave = :$chave";
	}
	$sqlCheck = sprintf(
		"SELECT id FROM %s WHERE %s LIMIT 1",
		$tabela,
		implode(' AND ', $where)
	);
	$stmtCheck = $db->prepare($sqlCheck);
	foreach ($chavesPrimarias as $chave) {
		$valor = $dados[$chave] ?? '';
		if (is_int($valor)) {
			$stmtCheck->bindValue(':' . $chave, $valor, SQLITE3_INTEGER);
		} else {
			$stmtCheck->bindValue(':' . $chave, $valor, SQLITE3_TEXT);
		}
	}
	$result = $stmtCheck->execute();
	$row = $result->fetchArray(SQLITE3_ASSOC);
	if ($row && isset($row['id'])) {
		return (int)$row['id'];
	}

	// Insere normalmente se não existir
	$columns = array_keys($dados);
	$placeholders = array_map(fn($col) => ':' . $col, $columns);

	$sql = sprintf(
		"INSERT INTO %s (%s) VALUES (%s)",
		$tabela,
		implode(', ', $columns),
		implode(', ', $placeholders)
	);

	$stmt = $db->prepare($sql);

	foreach ($dados as $col => $val) {
		if (is_int($val)) {
			$stmt->bindValue(':' . $col, $val, SQLITE3_INTEGER);
		} else {
			$stmt->bindValue(':' . $col, $val ?? '', SQLITE3_TEXT);
		}
	}

	$stmt->execute();
	return $db->lastInsertRowID();
}

function parseIgreja(Crawler $divs): array {
	// === IGREJA ===
	$igrejaDiv = $divs->reduce(function (Crawler $node) {
		return $node->attr('style') === 'padding: 15px;';
	})->first();

	$igreja = [
		'nome' => $igrejaDiv->filter('big > b')->text(''),
		'presbiterio' => $igrejaDiv->filter('b')->eq(1)->text(''),
	];

	$igrejaHtml = $igrejaDiv->html();

	if (preg_match('/<br><br>(?<endereco>[^<]+)<br>/', $igrejaHtml, $match)) {
		$igreja['endereco'] = trim($match['endereco']);

		// Extrai município e UF do endereço
		if (preg_match('/ (?<municipio>[^-\/\n]+)\s*\/\s*(?<uf>[A-Z]{2})$/m', $igreja['endereco'], $munUf)) {
			$igreja['municipio'] = trim($munUf['municipio']);
			$igreja['uf'] = trim($munUf['uf']);
			// Remove município e UF do final do endereço
			$igreja['endereco'] = trim(preg_replace('/\s*' . preg_quote($munUf[0], '/') . '$/', '', $igreja['endereco']));
		}
	}

	if (preg_match('/<br>CEP: (?<cep>[\d\.-]{0,})<br>/', $igrejaHtml, $match)) {
		$igreja['cep'] = trim($match['cep']);
	}

	// Telefone, emails e website
	$igrejaDiv->filter('a')->each(function (Crawler $a) use (&$igreja) {
		$href = $a->attr('href');

		if (str_starts_with($href, 'tel:')) {
			$tel = trim(str_replace('tel:', '', $href));
			$tel = str_replace('%20', ' ', $tel);
			$tel = preg_replace('/[^\d\s\+\-\(\)]/', '', $tel); // mantém apenas números, espaços, +, -, ()
			$igreja['tel'] = $tel;
		} elseif (str_starts_with($href, 'mailto:')) {
			$igreja['email'] = trim(str_replace('mailto:', '', $href));
		} elseif (str_starts_with($href, 'http') || str_starts_with($href, 'www')) {
			$igreja['website'] = trim($href);
		}
	});
	return $igreja;
}

function parsePastor(Crawler $divs): ?array {
	$pastorDiv = $divs->reduce(function (Crawler $node) {
		return str_contains($node->attr('style') ?? '', 'background-color: rgba(0,0,0,0.05);');
	})->first(null);

	if (!$pastorDiv) {
		return null;
	}

	$pastor = ['nome' => trim($pastorDiv->filter('b > small')->text(''))];

	$pastorHtml = $pastorDiv->html();

	if (preg_match('/Tel: <a href="tel:(?<tel>[^"]+)"/m', $pastorHtml, $match)) {
		$pastor['tel'] = $match['tel'];
		$pastor['tel'] = str_replace('%20', ' ', $pastor['tel']);
		$pastor['tel'] = preg_replace('/[^\d\s\+\-\(\)]/', '', $pastor['tel']); // mantém apenas números, espaços, +, -, ()
	}

	if (preg_match('/Cel: <a href="tel:(?<cel>[^"]+)"/m', $pastorHtml, $match)) {
		$pastor['cel'] = $match['cel'];
		$pastor['cel'] = str_replace('%20', ' ', $pastor['cel']);
		$pastor['cel'] = preg_replace('/[^\d\s\+\-\(\)]/', '', $pastor['cel']); // mantém apenas números, espaços, +, -, ()
	}

	if (preg_match('/Email: <a href="mailto:(?<email>[^"]+)"/m', $pastorHtml, $match)) {
		$pastor['email'] = $match['email'];
	}

	return $pastor;
}

$html = file_get_contents('tmp.html');
extrairBlocosIgrejaPastor($html);
