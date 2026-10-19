document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('searchInput');
    const searchButton = document.getElementById('searchButton');
    const herbTableBody = document.getElementById('herbTableBody');
    let herbsData = null; // Initialize to null initially

    // Function to render the herbs table
    function renderHerbsTable(data) {
        herbTableBody.innerHTML = ''; // Clear previous table content
        data.forEach(herb => {
            const row = document.createElement('tr');
            Object.values(herb).forEach(value => {
                const cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
            });
            herbTableBody.appendChild(row);
        });
    }

    // Function to perform search based on keyword
    function performSearch(keyword) {
        keyword = keyword.trim().toLowerCase();
        if (herbsData) { // Check if herbsData is not null or undefined
            const filteredHerbs = herbsData.filter(herb => {
                return Object.values(herb).some(value =>
                    value && value.toString().toLowerCase().includes(keyword)
                );
            });
            renderHerbsTable(filteredHerbs); // Render the filtered data
        }
    }

    // Fetch herbs data from herbs.json
    fetch('herbs.json')
        .then(response => response.json())
        .then(data => {
            herbsData = data; // Store the fetched data
            renderHerbsTable(herbsData); // Initial rendering of the table
        })
        .catch(error => {
            console.error('Error fetching data:', error);
        });

    // Event listener for the search button
    searchButton.addEventListener('click', () => {
        const keyword = searchInput.value;
        if (keyword) { // Check if search input is not empty
            performSearch(keyword.trim());
        }
    });

    // Optional: Add event listener for Enter key press in the search input field
    searchInput.addEventListener('keypress', event => {
        if (event.key === 'Enter') {
            const keyword = searchInput.value;
            if (keyword) { // Check if search input is not empty
                performSearch(keyword.trim());
            }
        }
    });
});


