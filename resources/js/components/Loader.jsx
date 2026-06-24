// resources/js/components/Loader.jsx
import React from 'react';
import './Loader.css'; // Стили вынесены отдельно

const Loader = () => {
  return (
    <div className="loader-container">
      <div className="loader-spinner"></div>
      <p className="loader-text">Загрузка...</p>
    </div>
  );
};

export default Loader;
