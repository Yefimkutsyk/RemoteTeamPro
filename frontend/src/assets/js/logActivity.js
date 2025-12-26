// logActivity.js
function logActivity(actionType, details = '') {
  fetch('/RemoteTeamPro/backend/api/activity-log.php?action=add', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include', // important for session
    body: JSON.stringify({
      action_type: actionType,
      details: details
    })
  })
  .then(res => res.json())
  .then(data => console.log('Activity logged:', data))
  .catch(err => console.error('Error logging activity:', err));
}
