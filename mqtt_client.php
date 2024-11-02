<?php
require 'vendor/autoload.php';
include 'database.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Exceptions\MqttClientException;

$server = 'broker_address';  // Ganti broker gatau
$port = 1883;
$clientId = 'php-mqtt-subscriber-aktuator';

try {
    $mqtt = new MqttClient($server, $port, $clientId);
    $mqtt->connect();

    echo "Terhubung ke broker MQTT...\n";

    // Fungsi untuk memperbarui status perangkat di database
    function updateDeviceStatus($deviceName, $status) {
        global $pdo;
        $sql = "INSERT INTO devices (device_name, status, timestamp) 
                VALUES (:device_name, :status, NOW()) 
                ON DUPLICATE KEY UPDATE status = :status, timestamp = NOW()";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':device_name', $deviceName);
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->execute();
    }

    // Fungsi untuk mencatat log aksi ke tabel actions_log
    function logAction($actionType, $deviceName = null) {
        global $pdo;
        $deviceId = null;
        if ($deviceName !== null) {
            // ID device diambil
            $stmt = $pdo->prepare("SELECT id FROM devices WHERE device_name = :device_name");
            $stmt->bindParam(':device_name', $deviceName);
            $stmt->execute();
            $device = $stmt->fetch();
            $deviceId = $device ? $device['id'] : null;
        }

        $sql = "INSERT INTO actions_log (action_type, device_id, timestamp) VALUES (:action_type, :device_id, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':action_type', $actionType);
        $stmt->bindParam(':device_id', $deviceId, PDO::PARAM_INT);
        $stmt->execute();
    }

    // Fungsi untuk mengirim perintah aktuator dan memperbarui database
    function kontrolAktuator($mqtt, $kipas, $lampu) {
        global $pdo;

        // Kirim perintah untuk kipas
        $kipasStatus = $kipas ? 1 : 0;
        $mqtt->publish('aktuator/kipas', $kipas ? 'ON' : 'OFF');
        echo $kipas ? "Kipas dinyalakan\n" : "Kipas dimatikan\n";
        updateDeviceStatus('Fan', $kipasStatus);
        logAction('Device Status Change', 'Fan');

        // Kirim perintah untuk lampu
        $lampuStatus = $lampu ? 1 : 0;
        $mqtt->publish('aktuator/lampu', $lampu ? 'ON' : 'OFF');
        echo $lampu ? "Lampu dinyalakan\n" : "Lampu dimatikan\n";
        updateDeviceStatus('Lamp', $lampuStatus);
        logAction('Device Status Change', 'Lamp');
    }

    // subs ke topik sensor buat ambil data
    $mqtt->subscribe('sensor/data', function (string $topic, string $message) use ($mqtt) {
        global $pdo;
        
        echo sprintf("Pesan diterima di topik [%s]: %s\n", $topic, $message);

        // JSON untuk mendapatkan nilai sensor
        $data = json_decode($message, true);
        if ($data) {
            $temperature = $data['temperature'] ?? null;
            $humidity = $data['humidity'] ?? null;
            $gas_level = $data['gas_level'] ?? null;

            // Simpan data ke database
            if ($temperature !== null && $humidity !== null && $gas_level !== null) {
                $sql = "INSERT INTO sensors (temperature, humidity, gas_level, timestamp) VALUES (:temperature, :humidity, :gas_level, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':temperature', $temperature);
                $stmt->bindParam(':humidity', $humidity);
                $stmt->bindParam(':gas_level', $gas_level);

                if ($stmt->execute()) {
                    echo "Data berhasil disimpan ke database.\n";
                    logAction('Sensor Data Update');  // log pembaruan data sensor
                } else {
                    echo "Gagal menyimpan data ke database.\n";
                }
            } else {
                echo "Data tidak valid.\n";
            }

            // utak atik batasan
            $temp_max = 30; // buat nyalain kipas
            $temp_min = 20; // buat nyalain lampu

            // Logika kontrol aktuator berdasarkan suhu
            if ($temperature > $temp_max) {
                kontrolAktuator($mqtt, true, false);  // Nyalakan kipas, matikan lampu
            } elseif ($temperature < $temp_min) {
                kontrolAktuator($mqtt, false, true);  // Matikan kipas, nyalakan lampu
            } else {
                kontrolAktuator($mqtt, false, false); // Matikan keduanya
            }
        } else {
            echo "Gagal decode pesan JSON.\n";
        }
    }, 0);

    // loop untuk mendengarkan pesan dari broker
    $mqtt->loop(true);

    // Menutup koneksi setelah selesai
    $mqtt->disconnect();
} catch (MqttClientException $e) {
    echo "Kesalahan MQTT: " . $e->getMessage();
} catch (PDOException $e) {
    echo "Kesalahan Database: " . $e->getMessage();
}
