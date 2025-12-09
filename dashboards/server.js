const WebSocket = require('ws');
const mysql = require('mysql2/promise');

const wss = new WebSocket.Server({ port: 4000 });

const dbConfig = {
    host: 'localhost',
    user: 'root',
    password: '',
    database: 'system'
};

const clients = new Map(); // Map department to WebSocket clients

wss.on('connection', ws => {
    console.log('New client connected');
    
    ws.on('message', async message => {
        try {
            const data = JSON.parse(message);
            if (data.type === 'subscribe') {
                clients.set(data.department, ws);
                await broadcastCommunications(data.department);
                await broadcastPendingOrders(data.department);
                await broadcastAlerts(data.department);
        } else if (data.type === 'new_message' || data.type === 'request_update' || data.type === 'new_checkout') {
            await broadcastCommunications(data.department);
            await broadcastPendingOrders(data.department);
            await broadcastAlerts(data.department);
            await broadcastStats(data.department); // Real-time dashboard stats update
            }
        } catch (error) {
            console.error('Message error:', error);
        }
    });

    ws.on('close', () => {
        console.log('Client disconnected');
        for (let [dept, client] of clients) {
            if (client === ws) {
                clients.delete(dept);
                break;
            }
        }
    });
});

async function broadcastCommunications(department) {
    const conn = await mysql.createConnection(dbConfig);
    try {
        const [rows] = await conn.execute(
            'SELECT * FROM communications WHERE from_department = ? OR to_department = ? ORDER BY timestamp DESC LIMIT 50',
            [department, department]
        );
        const clientsToNotify = [...clients.entries()].filter(([dept]) => 
            dept === department || rows.some(row => row.from_department === dept || row.to_department === dept)
        );
        clientsToNotify.forEach(([dept, client]) => {
            if (client.readyState === WebSocket.OPEN) {
                client.send(JSON.stringify({ type: 'communications', messages: rows }));
            }
        });
    } catch (error) {
        console.error('Broadcast communications error:', error);
    } finally {
        await conn.end();
    }
}

async function broadcastPendingOrders(department) {
    const conn = await mysql.createConnection(dbConfig);
    try {
        const [rows] = await conn.execute(
            'SELECT COUNT(*) AS count FROM borrowing_requests WHERE to_department = ? AND status = "pending"',
            [department]
        );
        const client = clients.get(department);
        if (client && client.readyState === WebSocket.OPEN) {
            client.send(JSON.stringify({ type: 'pending_orders', count: rows[0].count }));
        }
    } catch (error) {
        console.error('Broadcast pending orders error:', error);
    } finally {
        await conn.end();
    }
}

async function broadcastStats(department) {
    const conn = await mysql.createConnection(dbConfig);
    try {
        // Get total drugs count for the department
        const [totalRows] = await conn.execute(
            'SELECT COUNT(*) AS total_drugs FROM drugs WHERE department = ?',
            [department]
        );

        // Get critical stock count (expired or expiring within 30 days)
        const [criticalRows] = await conn.execute(
            `SELECT COUNT(*) AS critical_stock
             FROM drugs
             WHERE department = ?
             AND (
                 expiry_date <= CURDATE()
                 OR expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             )`,
            [department]
        );

        // Get safe stock count (expiring after 2 months)
        const [safeRows] = await conn.execute(
            `SELECT COUNT(*) AS safe_stock
             FROM drugs
             WHERE department = ?
             AND expiry_date > DATE_ADD(CURDATE(), INTERVAL 2 MONTH)`,
            [department]
        );

        const statsData = {
            total_drugs: totalRows[0].total_drugs,
            critical_stock: criticalRows[0].critical_stock,
            safe_stock: safeRows[0].safe_stock
        };

        const client = clients.get(department);
        if (client && client.readyState === WebSocket.OPEN) {
            client.send(JSON.stringify({
                type: 'dashboard_stats',
                stats: statsData
            }));
        }
    } catch (error) {
        console.error('Broadcast stats error:', error);
    } finally {
        await conn.end();
    }
}

async function broadcastAlerts(department) {
    const conn = await mysql.createConnection(dbConfig);
    try {
        const [rows] = await conn.execute(
            `SELECT drug_name, department, current_stock, min_stock, status,
                    DATEDIFF(expiry_date, CURDATE()) AS days_to_expiry
             FROM drugs
             WHERE (current_stock <= min_stock
                    OR (expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                        AND expiry_date >= CURDATE()))
                   AND status != 'expired'`,
            []
        );
        // Broadcast to all connected clients, as critical stock alerts may be relevant to multiple departments
        for (let [dept, client] of clients) {
            if (client.readyState === WebSocket.OPEN) {
                client.send(JSON.stringify({ type: 'alerts', alerts: rows }));
            }
        }
    } catch (error) {
        console.error('Broadcast alerts error:', error);
    } finally {
        await conn.end();
    }
}

console.log('WebSocket server running on ws://localhost:4000');
