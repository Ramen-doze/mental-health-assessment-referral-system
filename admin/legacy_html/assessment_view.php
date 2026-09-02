<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php";

function table_exists($conn, $name) {
    $name_esc = mysqli_real_escape_string($conn, $name);
    $sql = "SHOW TABLES LIKE '$name_esc'";
    $res = mysqli_query($conn, $sql);
    return ($res && mysqli_num_rows($res) > 0);
}

function column_exists($conn, $table, $column) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $column);
    $sql = "SHOW COLUMNS FROM `$t` LIKE '$c'";
    $res = mysqli_query($conn, $sql);
    return ($res && mysqli_num_rows($res) > 0);
}

function get_row_by_id($conn, $table, $id_column, $id_value) {
    $table_esc = mysqli_real_escape_string($conn, $table);
    $id_col_esc = mysqli_real_escape_string($conn, $id_column);
    $id_value_esc = mysqli_real_escape_string($conn, (string)$id_value);
    $sql = "SELECT * FROM `$table_esc` WHERE `$id_col_esc` = '$id_value_esc' LIMIT 1";
    $res = mysqli_query($conn, $sql);
    if (!$res || mysqli_num_rows($res) === 0) {
        return null;
    }
    return mysqli_fetch_assoc($res);
}

function display_value($value) {
    if ($value === null) {
        return '—';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$candidates = ['assessment_data','assessments','assessment','assessment_results','assessment_submissions','assessment_records'];
$atable = null;
foreach ($candidates as $c) {
    if (table_exists($conn, $c)) {
        $atable = $c;
        break;
    }
}

if (!$atable) {
    echo "<p>No assessment table detected.</p>";
    exit();
}

$id_col = null;
foreach (['assessment_id','id','submission_id','record_id','entry_id'] as $c) {
    if (column_exists($conn, $atable, $c)) {
        $id_col = $c;
        break;
    }
}
if (!$id_col) {
    echo "<p>Could not find an ID column in $atable.</p>";
    exit();
}

$req_id = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($req_id === '') {
    echo "<p>Missing assessment id.</p>";
    exit();
}

$assessment = get_row_by_id($conn, $atable, $id_col, $req_id);
if (!$assessment) {
    echo "<p>Assessment not found.</p>";
    exit();
}

$student_name = 'Unknown student';
if (table_exists($conn, 'user_data') && isset($assessment['user_id'])) {
    $user_id = (int)$assessment['user_id'];
    $user_result = mysqli_query($conn, "SELECT fullname, email, student_number FROM user_data WHERE user_id = $user_id LIMIT 1");
    if ($user_result && mysqli_num_rows($user_result) > 0) {
        $user = mysqli_fetch_assoc($user_result);
        $student_name = $user['fullname'] ?? 'Unknown student';
    }
}

$responses = [];
if (table_exists($conn, 'response_data') && column_exists($conn, 'response_data', 'assessment_id')) {
    $response_sql = "SELECT * FROM response_data WHERE assessment_id = " . (int)$assessment[$id_col] . " ORDER BY response_id ASC";
    $response_result = mysqli_query($conn, $response_sql);
    if ($response_result) {
        while ($row = mysqli_fetch_assoc($response_result)) {
            $responses[] = $row;
        }
    }
}

$question_map = [];
if (table_exists($conn, 'question_data')) {
    $question_cols = ['question_id','id','question_no'];
    $question_text_cols = ['question_text','question','text','prompt','description'];
    $question_label_col = null;
    foreach ($question_cols as $c) {
        if (column_exists($conn, 'question_data', $c)) {
            $question_label_col = $c;
            break;
        }
    }
    $question_text_col = null;
    foreach ($question_text_cols as $c) {
        if (column_exists($conn, 'question_data', $c)) {
            $question_text_col = $c;
            break;
        }
    }

    if ($question_label_col && $question_text_col) {
        $question_sql = "SELECT `" . mysqli_real_escape_string($conn, $question_label_col) . "`, `" . mysqli_real_escape_string($conn, $question_text_col) . "` FROM question_data";
        $question_result = mysqli_query($conn, $question_sql);
        if ($question_result) {
            while ($row = mysqli_fetch_assoc($question_result)) {
                $qid = $row[$question_label_col] ?? null;
                $qtext = $row[$question_text_col] ?? null;
                if ($qid !== null && $qtext !== null) {
                    $question_map[(string)$qid] = $qtext;
                }
            }
        }
    }
}
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Assessment Details</title>
    <style>
      * { box-sizing: border-box; }
      body {
        font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        margin: 0;
        padding: 24px;
        background: #efeeeb;
        color: #1a1a1a;
      }
      .wrap { max-width: 1100px; margin: 0 auto; }
      .card {
        background: rgba(255,255,255,0.2);
        border: 1px solid #dfe4dc;
        border-radius: 12px;
        padding: 20px 22px;
        margin-top: 20px;
      }
      h1 { margin-top: 0; }
      .back-link { display: inline-block; margin-bottom: 12px; color: #2d5c3c; font-weight: 600; text-decoration: none; }
      table.detail-table {
        width: 100%; border-collapse: collapse;
      }
      .detail-table th, .detail-table td {
        border-bottom: 1px solid #dfe4dc; padding: 10px 8px; text-align: left; vertical-align: top;
      }
      .detail-table th { width: 220px; background: rgba(255,255,255,0.12); }
      .response-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
      .response-table th, .response-table td { border: 1px solid #dfe4dc; padding: 10px; text-align: left; }
      .muted { color: #666; }
      @media (max-width: 700px) {
        body { padding: 16px; }
        .detail-table th { width: 100%; display: block; }
      }
    </style>
  </head>
  <body>
    <div class="wrap">
      <a class="back-link" href="assessments.php">&larr; Back to assessments</a>

      <h1>Assessment Details</h1>
      <div class="card">
        <table class="detail-table">
          <tbody>
            <tr><th>Assessment ID</th><td><?= display_value($assessment[$id_col] ?? $assessment['assessment_id'] ?? '') ?></td></tr>
            <tr><th>Student</th><td><?= display_value($student_name) ?></td></tr>
            <tr><th>PHQ-9 Score</th><td><?= display_value($assessment['phq9_score'] ?? '') ?></td></tr>
            <tr><th>PHQ Level</th><td><?= display_value($assessment['phq_level'] ?? '') ?></td></tr>
            <tr><th>GAD-7 Score</th><td><?= display_value($assessment['gad7_score'] ?? '') ?></td></tr>
            <tr><th>GAD Level</th><td><?= display_value($assessment['gad_level'] ?? '') ?></td></tr>
            <tr><th>Risk Level</th><td><?= display_value($assessment['risk_level'] ?? '') ?></td></tr>
            <tr><th>Status</th><td><?= display_value($assessment['status'] ?? '') ?></td></tr>
            <tr><th>Assessment Date</th><td><?= display_value($assessment['assessment_date'] ?? ($assessment['created_at'] ?? '')) ?></td></tr>
            <?php if (isset($assessment['user_id'])): ?>
              <tr><th>User ID</th><td><?= display_value($assessment['user_id']) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if (!empty($responses)): ?>
        <div class="card">
          <h2>Response Breakdown</h2>
          <table class="response-table">
            <thead>
              <tr>
                <th>Question</th>
                <th>Answer Score</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($responses as $response): ?>
                <tr>
                  <td>
                    <?php
                      $qid = $response['question_id'] ?? $response['question_no'] ?? null;
                      $label = $question_map[(string)$qid] ?? ('Question ' . ($qid ?? 'N/A'));
                      echo display_value($label);
                    ?>
                  </td>
                  <td><?= display_value($response['answer_score'] ?? $response['score'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </body>
</html>
