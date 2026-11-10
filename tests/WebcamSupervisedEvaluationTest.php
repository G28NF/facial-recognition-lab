<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlVideoSessionRepository;

$root = require __DIR__.'/bootstrap.php';
$pdo = (new PdoConnection(require $root.'/config/database.php'))->pdo();
$ids = $pdo->query('SELECT id FROM identities WHERE active=1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$createdIds = [];
while (count($ids) < 2) {
    $insertIdentity = $pdo->prepare('INSERT INTO identities (public_id, name_ciphertext, name_nonce, name_auth_tag, name_key_id, name_lookup_hash, consented_at, active)
        VALUES (:public_id, :ciphertext, :nonce, :tag, "test", :lookup_hash, UTC_TIMESTAMP(), 1)');
    $insertIdentity->execute(['public_id' => random_bytes(16), 'ciphertext' => random_bytes(32), 'nonce' => random_bytes(12), 'tag' => random_bytes(16), 'lookup_hash' => random_bytes(32)]);
    $createdIds[] = (int) $pdo->lastInsertId();
    $ids[] = end($createdIds);
}
$repository = new MysqlVideoSessionRepository(new PdoConnection(require $root.'/config/database.php'));
$token = $repository->start(1000, ['A' => 1.0, 'B' => 1.0], (int) $ids[0]);
$group = hex2bin($token);
if (!is_string($group)) throw new RuntimeException('Token invalido.');
$interruptedToken = null;
$replacementToken = null;
$round = static fn(string $a, ?int $idA, string $b, ?int $idB): array => [
    'A' => ['status'=>$a,'id'=>$idA,'distance_squared'=>.1,'compared_embeddings'=>1,'metrics'=>['pipeline_total_ms'=>1.0]],
    'B' => ['status'=>$b,'id'=>$idB,'distance_squared'=>.2,'compared_embeddings'=>1,'metrics'=>['pipeline_total_ms'=>2.0]],
];
try {
    $repository->recordRound($token, 1, 0, $round('recognized', (int)$ids[0], 'recognized', (int)$ids[1]), [], ['A'=>.36,'B'=>1.3225]);
    $repository->changeEvaluationIdentity($token, (int)$ids[1]);
    $repository->recordRound($token, 2, 0, $round('unknown', null, 'error', null), [], ['A'=>.36,'B'=>1.3225]);
    $query = $pdo->prepare('SELECT outcome, COUNT(*) amount FROM webcam_evaluation_results r INNER JOIN webcam_evaluation_segments s ON s.id=r.webcam_evaluation_segment_id WHERE s.session_group_id=:group GROUP BY outcome');
    $query->bindValue(':group',$group,PDO::PARAM_LOB); $query->execute(); $counts=[]; foreach($query as $row) $counts[$row['outcome']] = (int)$row['amount'];
    if (($counts['correct']??0)!==1 || ($counts['incorrect']??0)!==1 || ($counts['not_recognized']??0)!==1 || ($counts['technical_failure']??0)!==1) throw new RuntimeException('Classificacao supervisionada incorreta.');
    $segments=$pdo->prepare('SELECT COUNT(*) FROM webcam_evaluation_segments WHERE session_group_id=:group'); $segments->bindValue(':group',$group,PDO::PARAM_LOB); $segments->execute();
    if ((int)$segments->fetchColumn()!==2) throw new RuntimeException('Troca de identidade nao criou novo segmento.');
    $repository->end($token);
    $closed=$pdo->prepare('SELECT COUNT(*) FROM webcam_evaluation_segments WHERE session_group_id=:group AND ended_at IS NOT NULL'); $closed->bindValue(':group',$group,PDO::PARAM_LOB); $closed->execute();
    if ((int)$closed->fetchColumn()!==2) throw new RuntimeException('Encerramento normal deixou segmento supervisionado aberto.');

    $interruptedToken = $repository->start(1000, ['A' => 1.0, 'B' => 1.0], (int) $ids[0]);
    $interruptedGroup = hex2bin($interruptedToken);
    if (!is_string($interruptedGroup)) throw new RuntimeException('Token interrompido invalido.');
    $replacementToken = $repository->start(1000, ['A' => 1.0, 'B' => 1.0], (int) $ids[1]);
    $closed->bindValue(':group',$interruptedGroup,PDO::PARAM_LOB); $closed->execute();
    if ((int)$closed->fetchColumn()!==1 || $repository->status($interruptedToken)['status'] !== 'interrupted') throw new RuntimeException('Interrupcao de sessao deixou segmento aberto.');
    $replacementGroup = hex2bin($replacementToken);
    if (!is_string($replacementGroup)) throw new RuntimeException('Token substituto invalido.');
    $open=$pdo->prepare('SELECT COUNT(*) FROM webcam_evaluation_segments WHERE session_group_id=:group AND ended_at IS NULL'); $open->bindValue(':group',$replacementGroup,PDO::PARAM_LOB); $open->execute();
    if ((int)$open->fetchColumn()!==1) throw new RuntimeException('Novo segmento supervisionado nao permaneceu aberto.');
    $repository->end($replacementToken);
} finally {
    foreach ([$token, $interruptedToken, $replacementToken] as $temporaryToken) {
        if (!is_string($temporaryToken) || !is_string($temporaryGroup = hex2bin($temporaryToken))) continue;
        $delete=$pdo->prepare('DELETE FROM video_sessions WHERE session_group_id=:group'); $delete->bindValue(':group',$temporaryGroup,PDO::PARAM_LOB); $delete->execute();
        $delete=$pdo->prepare('DELETE FROM webcam_evaluation_segments WHERE session_group_id=:group'); $delete->bindValue(':group',$temporaryGroup,PDO::PARAM_LOB); $delete->execute();
    }
    if ($createdIds !== []) {
        $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
        $deleteIdentities = $pdo->prepare("DELETE FROM identities WHERE id IN ({$placeholders})");
        $deleteIdentities->execute($createdIds);
    }
}
echo "OK - webcam supervisionada, resultados A/B e segmentos por identidade\n";
