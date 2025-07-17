<?php
require 'vendor/autoload.php';

use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;

$db = new SQLite3('igrejas.db');
$db->exec("CREATE TABLE IF NOT EXISTS igrejas (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	nome TEXT NOT NULL,
	presbiterio TEXT,
	endereco TEXT,
	municipio TEXT,
	uf TEXT,
	cep TEXT,
	tel TEXT,
	email TEXT,
	website TEXT,
	website_dado_original TEXT,
	website_validado INTEGER DEFAULT 0,
	website_dados_lgpd INTEGER DEFAULT 0,
	UNIQUE(nome, presbiterio, municipio, uf) ON CONFLICT IGNORE
)");

$db->exec("CREATE TABLE IF NOT EXISTS pastores (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	nome TEXT NOT NULL,
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

function extrairBlocosIgrejaPastor(Crawler $crawler): void {
	$atual = 0;
	$igrejas = $crawler->filter('div[style^="font-family: Helvetica, Arial; background-color: rgba(0,0,0,0.05);"]');

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
			$igreja['endereco'] = preg_replace('/\s*' . preg_quote($munUf[0], '/') . '$/', '', $igreja['endereco']);
			$igreja['endereco'] = trim($igreja['endereco']);
			$igreja['endereco'] = preg_replace('/\s*-\s*$/', '', $igreja['endereco']);
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
			$tel = preg_replace('/[^\d\s\+\-\(\)]/', '', $tel);
			$igreja['tel'] = $tel;
		} elseif (str_starts_with($href, 'mailto:')) {
			$igreja['email'] = trim(str_replace('mailto:', '', $href));
		} elseif (str_starts_with($href, 'http') || str_starts_with($href, 'www')) {
			$igreja['website_dado_original'] = trim($href);
			// Valida se é uma URL válida
			if (
				filter_var($igreja['website_dado_original'], FILTER_VALIDATE_URL) &&
				!in_array($igreja['website_dado_original'], [
					'http://yahoo.com.br/',
					'http://xn--nopossui-rza/',
					'http://gmail.com/',
				])
			) {
				$igreja['website'] = trim($href);
			}
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
		$pastor['tel'] = preg_replace('/[^\d\s\+\-\(\)]/', '', $pastor['tel']);
	}

	if (preg_match('/Cel: <a href="tel:(?<cel>[^"]+)"/m', $pastorHtml, $match)) {
		$pastor['cel'] = $match['cel'];
		$pastor['cel'] = str_replace('%20', ' ', $pastor['cel']);
		$pastor['cel'] = preg_replace('/[^\d\s\+\-\(\)]/', '', $pastor['cel']);
	}

	if (preg_match('/Email: <a href="mailto:(?<email>[^"]+)"/m', $pastorHtml, $match)) {
		$pastor['email'] = $match['email'];
	}

	return $pastor;
}

function limparWebsitesInvalidos(SQLite3 $db): void {
	// Remove websites inválidos por valor exato
	$db->exec("UPDATE igrejas SET website = null WHERE website IN ('http://gmail.com/', 'http://blogspot.com/', 'http://prvv.org.br/features/igrejas')");

	// Remove websites inválidos por padrão
	$db->exec("UPDATE igrejas SET website = null WHERE website LIKE '%facebook%' OR website LIKE '%youtube%' OR website LIKE '%fb.com%' OR website LIKE '%instagram%'");

	// Wix, webdnode, blogspot
	$db->exec("UPDATE igrejas SET website_validado = 3 WHERE website like '%wixsite%' OR website like '%webnode%' OR website like '%blogspot%'");

	// Não é site real, apenas um link de redirecionamento
	$db->exec("UPDATE igrejas SET website_validado = 4 WHERE website like '%apptuts.bio' OR website like '%linkr.bio%' OR website like '%linktr.ee%'");

	// 5 = Inovaki
	// 7 = Contém dados LGPD
}

function verificarUrlEAtualizar(SQLite3 $db): void {
	$result = $db->query("SELECT id, website FROM igrejas WHERE website IS NOT NULL AND website != ''");
	$rows = [];
	while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
		$rows[] = $row;
	}
	$total = count($rows);
	$atual = 0;

	foreach ($rows as $row) {
		$id = $row['id'];
		$url = $row['website'];

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOBODY         => true,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => false,
		]);
		curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$website_validado = ($httpCode >= 200 && $httpCode < 300) ? 2 : 1;

		$stmt = $db->prepare("UPDATE igrejas SET website_validado = :website_validado WHERE id = :id");
		$stmt->bindValue(':website_validado', $website_validado, SQLITE3_INTEGER);
		$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
		$stmt->execute();

		$atual++;
		echo "\rValidando websites: $atual de $total";
	}
	echo "\nValidação de websites concluída.\n";
}

function markaInovaki(SQLite3 $db): void {
	$result = $db->query("SELECT id, website FROM igrejas WHERE website_validado = 2");
	$rows = [];
	while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
		$rows[] = $row;
	}
	$total = count($rows);
	$atual = 0;
	$inovaki = 0;

	foreach ($rows as $row) {
		$id = $row['id'];
		$url = $row['website'];

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => false,
		]);
		$html = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// Se o HTML contiver 'Inovaki', define website_dados_lgpd = 'Inovaki'
		if (stripos($html, 'Inovaki') !== false) {
			$stmt = $db->prepare("UPDATE igrejas SET website_validado = :website_validado, website_dados_lgpd = :lgpd WHERE id = :id");
			$stmt->bindValue(':website_validado', 5, SQLITE3_INTEGER);
			$stmt->bindValue(':lgpd', 'Inovaki', SQLITE3_TEXT);
			$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
			$stmt->execute();
			$inovaki++;
		}

		$atual++;
		echo "\rValidando websites: $atual de $total; Inovaki: $inovaki";
	}
	echo "\nValidação de websites concluída.\n";
}

function marcaInChurch(SQLite3 $db): void {
	$result = $db->query("SELECT id, website FROM igrejas WHERE website_validado = 2");
	$rows = [];
	while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
		$rows[] = $row;
	}
	$total = count($rows);
	$atual = 0;
	$inchurch = 0;

	foreach ($rows as $row) {
		$id = $row['id'];
		$url = $row['website'];

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => false,
		]);
		$html = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// Se o HTML contiver 'inchurch', define website_dados_lgpd = 'inchurch'
		if (stripos($html, 'inradar') !== false) {
			$stmt = $db->prepare("UPDATE igrejas SET website_validado = :website_validado, website_dados_lgpd = :lgpd WHERE id = :id");
			$stmt->bindValue(':website_validado', 5, SQLITE3_INTEGER);
			$stmt->bindValue(':lgpd', 'inchurch', SQLITE3_TEXT);
			$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
			$stmt->execute();
			$inchurch++;
		}

		$atual++;
		echo "\rValidando websites: $atual de $total; inchurch: $inchurch";
	}
	echo "\nValidação de websites concluída.\n";
}

function marcaEklesia(SQLite3 $db): void {
	$result = $db->query("SELECT id, website FROM igrejas WHERE website_validado = 2 AND website_dados_lgpd IS NULL");
	$rows = [];
	while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
		$rows[] = $row;
	}
	$total = count($rows);
	$atual = 0;
	$eklesia = 0;

	foreach ($rows as $row) {
		$id = $row['id'];
		$url = $row['website'];

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => false,
		]);
		$html = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// Se o HTML contiver 'eklesia', define website_dados_lgpd = 'eklesia'
		if (stripos($html, 'inradar') !== false) {
			$stmt = $db->prepare("UPDATE igrejas SET website_validado = :website_validado, website_dados_lgpd = :lgpd WHERE id = :id");
			$stmt->bindValue(':website_validado', 5, SQLITE3_INTEGER);
			$stmt->bindValue(':lgpd', 'eklesia', SQLITE3_TEXT);
			$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
			$stmt->execute();
			$eklesia++;
		}

		$atual++;
		echo "\rValidando websites: $atual de $total; eklesia: $eklesia";
	}
	echo "\nValidação de websites concluída.\n";
}

function marcaSistemaProver(SQLite3 $db): void {
	$result = $db->query("SELECT id, website FROM igrejas WHERE website_validado = 2 AND website_dados_lgpd IS NULL");
	$rows = [];
	while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
		$rows[] = $row;
	}
	$total = count($rows);
	$atual = 0;
	$contador = 0;

	foreach ($rows as $row) {
		$id = $row['id'];
		$url = $row['website'];

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => false,
		]);
		$html = curl_exec($ch);
		curl_close($ch);

		// Se o HTML contiver 'siteprover', define website_dados_lgpd = 'sistema prover'
		if (stripos($html, 'inradar') !== false) {
			$stmt = $db->prepare("UPDATE igrejas SET website_validado = :website_validado, website_dados_lgpd = :lgpd WHERE id = :id");
			$stmt->bindValue(':website_validado', 5, SQLITE3_INTEGER);
			$stmt->bindValue(':lgpd', 'sistema prover', SQLITE3_TEXT);
			$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
			$stmt->execute();
			$contador++;
		}

		$atual++;
		echo "\rValidando websites: $atual de $total; prover: $contador";
	}
	echo "\nValidação de websites concluída.\n";
}

function getHtml(): Crawler {
	$data = [
		'buscar' => 'anu_igrejas',
		'tipo' => '1',
	];

	$options = [
		'http' => [
			'method'  => 'POST',
			'content' => http_build_query($data),
		],
	];

	$context = stream_context_create($options);

	$response = file_get_contents('https://www.icalvinus.app/consulta_ipb/anuario.php', false, $context);

	$crawler = new Crawler();
	$crawler->addHtmlContent($response);

	return $crawler;
}

$crawler = getHtml();
extrairBlocosIgrejaPastor($crawler);
verificarUrlEAtualizar($db);
limparWebsitesInvalidos($db);
markaInovaki($db);
marcaSistemaProver($db);
marcaInChurch($db);
marcaEklesia($db);


// N = Não compliance com LGPD
// PP = Política de privacidade
// AC = Aviso de cookies
// FDT = Formulário de direitos do titular
// TU = Termo de uso
// DPO = Data Protection Officer (Encarregado de Proteção de Dados)
