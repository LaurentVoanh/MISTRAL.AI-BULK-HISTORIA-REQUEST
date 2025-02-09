<?php

// Configuration de l'API Mistral
define('MISTRAL_API_KEY', getenv('MISTRAL_API_KEY') ?: ' YOUR API KEY ');
define('MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');
define('MISTRAL_MODEL', 'pixtral-12b-2409');
define('CHATROOM_DIR', '../CHATROOM');

// Mode debug (pas utilisé dans cet exemple, mais conservé pour cohérence)
$debug = 0;

// Vérifier et créer le dossier CHATROOM
if (!is_dir(CHATROOM_DIR)) {
    mkdir(CHATROOM_DIR);
}

/**
 * Envoie une requête à l'API Mistral.
 *
 * @param string $prompt Le prompt à envoyer à l'API.
 * @return string La réponse de l'API ou un message d'erreur.
 */
function queryMistralAPI($prompt)
{
    $api_key = MISTRAL_API_KEY;
    $endpoint_url = MISTRAL_ENDPOINT;
    $model = MISTRAL_MODEL;

    $data = [
        'model' => $model,
        'max_tokens' => 2500,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ],
    ];

    $ch = curl_init($endpoint_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_message = curl_error($ch);
        curl_close($ch);

        return 'Erreur lors de la requête à l\'API Mistral: ' . $error_message;
    }

    curl_close($ch);

    $decoded_response = json_decode($response, true);

    if ($decoded_response === null) {
        return 'Erreur lors du décodage de la réponse JSON.';
    }

    if (isset($decoded_response['choices'][0]['message']['content'])) {
        $htmlContent = $decoded_response['choices'][0]['message']['content'];
        $htmlContent = preg_replace('/^```html\s*/', '', $htmlContent);
        $htmlContent = preg_replace('/\s*```$/', '', $htmlContent);

        return $htmlContent;
    } else {
        return 'La réponse de l\'API Mistral ne contient pas le format attendu.';
    }
}

/**
 * Crée un fichier JSON contenant les informations de la requête et de la réponse.
 *
 * @param string $userInput La question de l'utilisateur.
 * @param string $apiResponse La réponse de l'API.
 * @param string $type Le type de données à enregistrer (initial, analyses).
 * @param array|null $analysesList La liste des analyses (si $type est 'analyses').
 * @return string|null Le nom du fichier créé ou null en cas d'erreur.
 */
function createJSONFile($userInput, $apiResponse, $type = 'initial', $analysesList = null)
{
    $ipAddress = $_SERVER['REMOTE_ADDR'];
    $date = date('YmdHis');
    $filename = CHATROOM_DIR . '/' . $date . '_' . str_replace('.', '_', $ipAddress) . '_' . $type . '.json';

    $data = [
        'ip' => $ipAddress,
        'date' => $date,
        'question' => $userInput,
        'reponse' => $apiResponse,
    ];

    if ($type === 'analyses' && $analysesList !== null) {
        $data['analyses'] = $analysesList;
    }

    $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if (file_put_contents($filename, $jsonData) !== false) {
        return $filename;
    } else {
        return null;
    }
}

/**
 * Obtient une liste d'analyses pertinentes à partir de l'API Mistral.
 *
 * @param string $subject Le sujet sur lequel effectuer les analyses.
 * @return array|string La liste des analyses ou un message d'erreur.
 */
function getAnalysesList($subject)
{
    $prompt = "Génère une liste JSON de 20 types d'analyses pertinentes et différentes pour le sujet suivant : \"$subject\". Adapte la liste dynamiquement en fonction du sujet pour maximiser sa pertinence. La liste JSON doit être un tableau d'objets, où chaque objet a une clé 'type' (chaîne de caractères) et une clé 'description' (chaîne de caractères).  Réponds uniquement avec le JSON valide.  Ne pas inclure de texte additionnel.";

    $response = queryMistralAPI($prompt);

    // Nettoyage renforcé: Supprimer tout texte avant le JSON
    $response = preg_replace('/^.*?(\[|\{)/s', '$1', $response);
    // Supprimer les balises ```json et ```
    $response = preg_replace('/^```json\s*/', '', $response);
    $response = preg_replace('/\s*```$/', '', $response);

    // Tentative de décodage de la réponse JSON
    $decodedResponse = json_decode($response, true);

    // Vérification si le décodage a réussi et si c'est bien une liste
    if (is_array($decodedResponse)) {
        return $decodedResponse;
    } else {
        return 'Erreur: Impossible de décoder la réponse en une liste JSON valide.  Réponse brute de l\'API: ' . $response;
    }
}

// Traitement de la requête POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'initial_question'; // Ajout d'une action pour distinguer les requêtes
    $userMessage = $_POST['user_input'] ?? '';

    if ($action === 'initial_question') {
        // 1. Réponse initiale de l'IA
        $apiResponse = queryMistralAPI($userMessage);

        // 2. Création du fichier JSON initial
        $jsonFile = createJSONFile($userMessage, $apiResponse);
        $jsonFileMessage = $jsonFile ? "Fichier JSON initial créé: $jsonFile" : 'Erreur lors de la création du fichier JSON initial.';

        // 3. Obtention de la liste des analyses
        $analysesList = getAnalysesList($userMessage);

        if (is_array($analysesList)) {
            // 4. Création et enregistrement du fichier JSON pour la liste des analyses
            $analysesJsonFile = createJSONFile($userMessage, json_encode($analysesList), 'analyses', $analysesList);
            $analysesJsonFileMessage = $analysesJsonFile ? "Fichier JSON des analyses créé: $analysesJsonFile" : 'Erreur lors de la création du fichier JSON des analyses.';

            // Préparer la réponse JSON avec toutes les informations
            $response = [
                'initialResponse' => $apiResponse,
                'jsonFileMessage' => $jsonFileMessage,
                'analysesList' => $analysesList,
                'analysesJsonFileMessage' => $analysesJsonFileMessage,
            ];
            echo json_encode($response);
        } else {
            // En cas d'erreur lors de l'obtention des analyses
            $response = [
                'initialResponse' => $apiResponse,
                'jsonFileMessage' => $jsonFileMessage,
                'analysesListError' => $analysesList, // Message d'erreur de getAnalysesList
            ];
            echo json_encode($response);
        }
    } elseif ($action === 'analyze_subject') {
        // Action pour l'analyse du sujet, à compléter avec le code pour interroger l'IA sur chaque analyse
        $analysisType = $_POST['analysis_type'] ?? '';
        $subject = $_POST['subject'] ?? '';
        $index = $_POST['index'] ?? 0;

        if ($analysisType && $subject) {
            // Construction du prompt pour l'analyse
            $analysisPrompt = "Analyse le sujet suivant : \"$subject\" en utilisant une approche $analysisType. Fournis une analyse détaillée et pertinente. Réponds de maniere magistrale comme pour grand maitre d'historien, en html, avec des titres.";

            // Obtention de la réponse de l'IA pour cette analyse
            $analysisResponse = queryMistralAPI($analysisPrompt);

            // Préparation de la réponse JSON pour l'analyse
            $response = [
                'analysisType' => $analysisType,
                'analysisResponse' => $analysisResponse,
                'index' => $index, // Renvoie l'index pour que le script puisse suivre
            ];
            echo json_encode($response);
        } else {
            echo json_encode(['error' => 'Type d\'analyse ou sujet manquant.']);
        }
    }

    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deep Culture</title>
    <style>
        body {
            font-family: Inter, sans-serif;
            background-color: #f5f5f7;
            color: #2c2c2c;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            background: linear-gradient(to right, #3498db, #8e44ad);
            -webkit-background-clip: text;
            color: transparent;
        }
        .form-container {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .input-box {
            display: flex;
            margin-bottom: 20px;
        }
        .input-box input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px 0 0 4px;
        }
        .input-box button {
            padding: 10px;
            border: 1px solid #ddd;
            background: #3498db;
            color: #fff;
            border-radius: 0 4px 4px 0;
            cursor: pointer;
        }
        #loader {
            display: none;
            margin-top: 20px;
            text-align: center; /* Centrer horizontalement le loader */
        }

        #loader img {
            width: 20px;   /* Définir la largeur de l'image à 20px */
            height: 20px;  /* Définir la hauteur de l'image à 20px */
        }
        .response-container {
            margin-top: 20px;
        }
        .error-message {
            color: red;
            margin-top: 10px;
        }
         .analyses-list {
            margin-top: 20px;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        .analyses-list h2 {
            font-size: 1.2em;
            margin-bottom: 10px;
            color: #555;
        }

        .analyses-list ul {
            list-style-type: none;
            padding: 0;
        }

        .analyses-list li {
            padding: 5px 10px;
            border-bottom: 1px solid #eee;
        }

        .analyses-list li:last-child {
            border-bottom: none;
        }

        .analysis-response {
            margin-top: 10px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #fff;
        }

        .analysis-response h3 {
            font-size: 1.1em;
            margin-bottom: 5px;
            color: #333;
        }

        .analysis-response p {
            font-size: 0.9em;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Deep Culture</h1>
        <div class="form-container">
            <form id="question-form">
                <div class="input-box">
                    <input type="text" id="user-input" name="user_input" placeholder="Posez votre question à deepseek.my.id" required>
                    <button type="submit">Envoyer</button>
                </div>
            </form>
        </div>
        <div id="loader"><img src="https://i.gifer.com/ZZ5H.gif" alt="Loading..."></div>
        <div class="response-container" id="response-container"></div>
        <div class="error-message" id="error-message"></div>
        <div class="analyses-list" id="analyses-list"></div>
    </div>

    <script>
        document.getElementById('question-form').addEventListener('submit', function(event) {
            event.preventDefault();
            sendMessage();
        });

        function sendMessage() {
            const userInput = document.getElementById('user-input').value;
            if (userInput.trim() === '') return;

            // Afficher le loader
            document.getElementById('loader').style.display = 'block';
            document.getElementById('error-message').textContent = '';
            document.getElementById('response-container').innerHTML = ''; // Efface la réponse précédente
            document.getElementById('analyses-list').innerHTML = ''; // Efface la liste précédente


            const formData = new FormData();
            formData.append('user_input', userInput);
            formData.append('action', 'initial_question'); // Indique l'action initiale

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json()) // Parse la réponse JSON
            .then(data => {
                // Masquer le loader
                document.getElementById('loader').style.display = 'none';

                if (data.error) {
                    document.getElementById('error-message').textContent = data.error;
                    return;
                }

                // Afficher la réponse initiale
                document.getElementById('response-container').innerHTML = '<h2>Réponse Initiale:</h2>' + data.initialResponse;

                // Afficher les messages de création de fichiers JSON
                if (data.jsonFileMessage) {
                    document.getElementById('response-container').innerHTML += '<p>' + data.jsonFileMessage + '</p>';
                }

                 // Afficher la liste des analyses
                 if (data.analysesList) {
                    let analysesListHTML = '<h2>Liste des Analyses Pertinentes:</h2><ul>';
                    data.analysesList.forEach(analysis => {
                        analysesListHTML += `<li>${analysis.type}: ${analysis.description}</li>`; // Affiche le type et la description
                    });
                    analysesListHTML += '</ul>';
                    document.getElementById('analyses-list').innerHTML = analysesListHTML;
                    if (data.analysesJsonFileMessage) {
                        document.getElementById('analyses-list').innerHTML += '<p>' + data.analysesJsonFileMessage + '</p>';
                    }

                    // Lancer l'analyse de chaque sujet avec un délai de 2 secondes
                    analyzeSubjectsWithDelay(userInput, data.analysesList, 0);

                } else if (data.analysesListError) {
                    document.getElementById('error-message').textContent = "Erreur lors de la récupération de la liste des analyses : " + data.analysesListError;
                }



            })
            .catch(error => {
                console.error('Error:', error);
                // Masquer le loader en cas d'erreur
                document.getElementById('loader').style.display = 'none';
                // Afficher un message d'erreur à l'utilisateur
                document.getElementById('error-message').textContent = "Une erreur s'est produite lors de la communication avec le serveur.";
            });

            document.getElementById('user-input').value = '';
        }

        function analyzeSubjectsWithDelay(subject, analysesList, index) {
             // Définir analysesList dans la portée de la fonction
            if (index >= analysesList.length) {
                return; // Terminer si toutes les analyses sont traitées
            }

            const analysis = analysesList[index];
            // Afficher le loader pour cette analyse
            let loaderDiv = document.createElement('div');
            loaderDiv.id = 'loader-' + index;
            loaderDiv.style.textAlign = 'center';
            loaderDiv.innerHTML = '<img src="https://i.gifer.com/ZZ5H.gif" alt="Loading..." style="width: 20px; height: 20px;">';
            document.getElementById('analyses-list').appendChild(loaderDiv);

            setTimeout(() => {
                analyzeSubject(subject, analysis, index, analysesList); // Passe analysesList
            }, 3000); // Délai de 3 secondes
        }


        // Fonction pour lancer l'analyse de chaque sujet
        function analyzeSubject(subject, analysis, index, analysesList) { // Reçoit analysesList

            const formData = new FormData();
            formData.append('action', 'analyze_subject'); // Indique l'action d'analyse
            formData.append('subject', subject);
            formData.append('analysis_type', analysis.type); // Envoie le type d'analyse
            formData.append('index', index); // Envoie l'index de l'analyse
            console.log("Analyse en cours:", analysis.type, "Index:", index);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                 // Supprimer le loader pour cette analyse
                const loaderDiv = document.getElementById('loader-' + index);
                if (loaderDiv) {
                    loaderDiv.remove();
                }
                if (data.error) {
                    document.getElementById('error-message').textContent += '<br>Erreur lors de l\'analyse ' + analysis.type + ': ' + data.error;
                    analyzeSubjectsWithDelay(subject, analysesList, index + 1); // Passer à l'analyse suivante même en cas d'erreur
                    return;
                }

                // Afficher la réponse de l'analyse
                const analysisResponseDiv = document.createElement('div');
                analysisResponseDiv.classList.add('analysis-response');
                analysisResponseDiv.innerHTML = `<h3>Analyse ${data.analysisType}:</h3><p>${data.analysisResponse}</p>`;
                document.getElementById('analyses-list').appendChild(analysisResponseDiv); // Ajoute la réponse sous la liste des analyses

                 // Incrémente l'index et lance l'analyse suivante
                analyzeSubjectsWithDelay(subject, analysesList, index + 1);
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('error-message').textContent += '<br>Erreur lors de l\'analyse ' + analysis.type + ': ' + error;
                analyzeSubjectsWithDelay(subject, analysesList, index + 1); // Passer à l'analyse suivante même en cas d'erreur
            });

        }
    </script>
</body>
</html>
